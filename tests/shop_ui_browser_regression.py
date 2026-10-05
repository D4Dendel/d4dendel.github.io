import base64
import hashlib
import json
import os
import secrets
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


BASE = "http://127.0.0.1/dyndel-portfolio/"
API = BASE + "api/index.php"
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
PHP = r"C:\xampp\php\php.exe"
MYSQL = r"C:\xampp\mysql\bin\mysql.exe"
DEBUG_PORT = 9334
ARTIFACT_DIR = os.environ.get("SHOP_UI_ARTIFACT_DIR")


def expect(condition, message):
    if not condition:
        raise AssertionError(message)


def api_request(action, method="GET", data=None, session_id=None):
    url = API + "?action=" + urllib.parse.quote(action)
    payload = None
    if method == "GET" and data:
        url += "&" + urllib.parse.urlencode(data)
    elif data is not None:
        payload = urllib.parse.urlencode(data).encode("utf-8")
    request = urllib.request.Request(url, data=payload, method=method)
    if session_id:
        request.add_header("Cookie", "PHPSESSID=" + session_id)
    try:
        response = urllib.request.urlopen(request, timeout=10)
        status = response.status
        body = response.read()
    except urllib.error.HTTPError as error:
        status = error.code
        body = error.read()
    return status, json.loads(body.decode("utf-8"))


class CdpClient:
    def __init__(self, websocket_url):
        parsed = urllib.parse.urlparse(websocket_url)
        self.socket = socket.create_connection((parsed.hostname, parsed.port), timeout=10)
        key = base64.b64encode(os.urandom(16)).decode("ascii")
        target = parsed.path + (("?" + parsed.query) if parsed.query else "")
        request = (
            f"GET {target} HTTP/1.1\r\n"
            f"Host: {parsed.hostname}:{parsed.port}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\n"
            "Sec-WebSocket-Version: 13\r\n\r\n"
        )
        self.socket.sendall(request.encode("ascii"))
        response = b""
        while b"\r\n\r\n" not in response:
            response += self.socket.recv(4096)
        expect(b" 101 " in response.split(b"\r\n", 1)[0], "Chrome WebSocket handshake failed")
        expected = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest())
        expect(expected in response, "Chrome WebSocket accept key was invalid")
        self.next_id = 1
        self.events = []
        self.runtime_errors = []

    def _frame(self, payload, opcode=1):
        data = payload if isinstance(payload, bytes) else payload.encode("utf-8")
        header = bytearray([0x80 | opcode])
        length = len(data)
        if length < 126:
            header.append(0x80 | length)
        elif length < 65536:
            header.append(0x80 | 126)
            header.extend(struct.pack("!H", length))
        else:
            header.append(0x80 | 127)
            header.extend(struct.pack("!Q", length))
        mask = os.urandom(4)
        header.extend(mask)
        masked = bytes(byte ^ mask[index % 4] for index, byte in enumerate(data))
        return bytes(header) + masked

    def _read_exact(self, length):
        result = b""
        while len(result) < length:
            chunk = self.socket.recv(length - len(result))
            if not chunk:
                raise RuntimeError("Chrome closed the DevTools connection")
            result += chunk
        return result

    def _receive(self):
        first, second = self._read_exact(2)
        opcode = first & 0x0F
        length = second & 0x7F
        if length == 126:
            length = struct.unpack("!H", self._read_exact(2))[0]
        elif length == 127:
            length = struct.unpack("!Q", self._read_exact(8))[0]
        mask = self._read_exact(4) if second & 0x80 else None
        payload = self._read_exact(length)
        if mask:
            payload = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
        if opcode == 9:
            self.socket.sendall(self._frame(payload, 10))
            return self._receive()
        if opcode == 8:
            raise RuntimeError("Chrome closed the DevTools connection")
        return json.loads(payload.decode("utf-8"))

    def call(self, method, params=None):
        command_id = self.next_id
        self.next_id += 1
        message = {"id": command_id, "method": method}
        if params is not None:
            message["params"] = params
        self.socket.sendall(self._frame(json.dumps(message, separators=(",", ":"))))
        while True:
            response = self._receive()
            if response.get("id") == command_id:
                if "error" in response:
                    raise RuntimeError(f"CDP {method} failed: {response['error']}")
                return response.get("result", {})
            self.events.append(response)
            if response.get("method") == "Runtime.exceptionThrown":
                details = response.get("params", {}).get("exceptionDetails", {})
                self.runtime_errors.append(details.get("text", "Uncaught browser exception"))
            if response.get("method") == "Log.entryAdded":
                entry = response.get("params", {}).get("entry", {})
                if entry.get("level") == "error" and entry.get("source") == "javascript":
                    self.runtime_errors.append(entry.get("text", "Browser JavaScript error"))

    def evaluate(self, expression, await_promise=False):
        result = self.call("Runtime.evaluate", {
            "expression": expression,
            "awaitPromise": await_promise,
            "returnByValue": True,
        })
        if "exceptionDetails" in result:
            raise RuntimeError(result["exceptionDetails"].get("text", "Browser evaluation failed"))
        return result.get("result", {}).get("value")

    def wait_for(self, expression, timeout=10):
        deadline = time.time() + timeout
        while time.time() < deadline:
            if self.evaluate(expression):
                return
            time.sleep(0.1)
        raise AssertionError("Browser condition timed out: " + expression)

    def navigate(self, url, condition="document.readyState === 'complete'"):
        self.call("Page.navigate", {"url": url})
        self.wait_for(condition, 15)

    def close(self):
        try:
            self.socket.sendall(self._frame(b"", 8))
        except OSError:
            pass
        self.socket.close()


def start_chrome(profile):
    process = subprocess.Popen([
        CHROME,
        "--headless=new",
        "--disable-background-networking",
        "--disable-default-apps",
        "--disable-extensions",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        "--remote-allow-origins=*",
        f"--remote-debugging-port={DEBUG_PORT}",
        f"--user-data-dir={profile}",
        "--window-size=1440,1000",
        "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    deadline = time.time() + 15
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(f"http://127.0.0.1:{DEBUG_PORT}/json/list", timeout=1) as response:
                targets = json.load(response)
            pages = [target for target in targets if target.get("type") == "page"]
            if pages:
                return process, pages[0]["webSocketDebuggerUrl"]
        except (OSError, urllib.error.URLError):
            time.sleep(0.1)
    process.terminate()
    raise RuntimeError("Headless Chrome did not expose its debugging endpoint")


def js_string(value):
    return json.dumps(value, ensure_ascii=False)


def capture_screenshot(cdp, filename):
    if not ARTIFACT_DIR:
        return
    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    result = cdp.call("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True})
    with open(os.path.join(ARTIFACT_DIR, filename), "wb") as image:
        image.write(base64.b64decode(result["data"]))


def normalized_preserved(product):
    return {
        "publicationStatus": product["publicationStatus"],
        "storefrontVisible": product["storefrontVisible"],
        "showWhenSoldOut": product["showWhenSoldOut"],
        "featured": product["featured"],
        "sortOrder": product["sortOrder"],
        "productType": product["productType"],
        "purchaseAction": product["purchaseAction"],
        "externalUrl": product["externalUrl"],
        "salePrice": product["salePrice"],
        "active": product["active"],
        "createdAt": product["createdAt"],
        "images": [{key: image[key] for key in ("id", "path", "altText", "sortOrder")} for image in product["images"]],
        "manualBadges": [{key: badge[key] for key in ("label", "sortOrder")} for badge in product["manualBadges"]],
    }


checks = 0
token = secrets.token_hex(4)
session_id = "shopuibrowser" + token
fixture_ids = []
chrome = None
cdp = None
profile = tempfile.mkdtemp(prefix="dyndel-shop-browser-")

auto_result = subprocess.run([
    MYSQL, "--host=127.0.0.1", "--user=root", "--batch", "--skip-column-names",
    "--database=dyndel_portfolio",
    "--execute=SELECT TABLE_NAME, COALESCE(AUTO_INCREMENT, 1) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('shop_products','shop_product_images','shop_product_badges') ORDER BY TABLE_NAME;",
], check=True, capture_output=True, text=True)
initial_auto_increments = dict(line.split("\t", 1) for line in auto_result.stdout.splitlines() if line)

subprocess.run([
    PHP,
    "-r",
    f'session_id("{session_id}"); session_start(); $_SESSION["admin_id"]="admin"; session_write_close();',
], check=True)

status, initial_admin = api_request("admin-products", session_id=session_id)
expect(status == 200, "Could not capture initial Admin product state")
initial_live = {product["id"]: product for product in initial_admin["products"]}
expect(set(initial_live) == {1, 2, 3}, "Expected exactly the three live products before browser testing")

try:
    chrome, websocket_url = start_chrome(profile)
    cdp = CdpClient(websocket_url)
    for method in ("Page.enable", "Runtime.enable", "Log.enable", "Network.enable"):
        cdp.call(method)

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
    })
    cdp.navigate(BASE + "graphic-design.html")
    cdp.evaluate("localStorage.removeItem('dyndelShopCart'); location.reload()")
    cdp.wait_for("document.readyState === 'complete' && document.querySelectorAll('.shop-product').length === 3", 15)
    cdp.wait_for("[...document.querySelectorAll('.shop-product img')].every(image => image.complete && image.naturalWidth > 0)", 15)
    desktop_cards = cdp.evaluate("""
        [...document.querySelectorAll('.shop-product')].map(card => ({
            image: card.querySelector('img')?.getAttribute('src') || '',
            imageLoaded: Boolean(card.querySelector('img')?.complete && card.querySelector('img')?.naturalWidth),
            title: card.querySelector('h2')?.textContent.trim() || '',
            description: card.querySelector('.shop-product-copy > p:not(.eyebrow)')?.textContent.trim() || '',
            price: card.querySelector('.shop-product-footer strong')?.textContent.trim() || '',
            action: card.querySelector('[data-add-cart]')?.textContent.trim() || '',
            disabled: Boolean(card.querySelector('[data-add-cart]')?.disabled)
        }))
    """)
    expect(len(desktop_cards) == 3, "Desktop Shop did not render three cards")
    expect(all(card["image"] and card["imageLoaded"] and card["title"] and card["description"] and card["price"] for card in desktop_cards), "A desktop product card was missing visible content")
    expect(all(card["action"] == "Add to cart" and not card["disabled"] for card in desktop_cards), "Desktop stock state/Add to Cart rendering changed")
    checks += 3

    first_price = float(desktop_cards[0]["price"].replace("$", ""))
    cdp.evaluate("document.querySelector('[data-add-cart]').click(); document.querySelector('[data-add-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '2'")
    cart_state = cdp.evaluate("({count: document.querySelector('[data-cart-count]').textContent, total: document.querySelector('[data-cart-total]').textContent, item: document.querySelector('.shop-cart-item span').textContent})")
    expect(cart_state["total"] == f"${first_price * 2:.2f}" and "× 2" in cart_state["item"], "Desktop cart quantity or total changed")
    cdp.evaluate("document.querySelector('[data-remove-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '0'")
    expect(cdp.evaluate("document.querySelector('[data-cart-total]').textContent") == "$0.00", "Desktop cart removal did not reset the total")
    checks += 2

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.navigate(BASE + "graphic-design.html", "document.readyState === 'complete' && document.querySelectorAll('.shop-product').length === 3")
    mobile_state = cdp.evaluate("({cards: document.querySelectorAll('.shop-product').length, cartDisplay: getComputedStyle(document.querySelector('[data-open-cart]')).display})")
    expect(mobile_state["cards"] == 3 and mobile_state["cartDisplay"] != "none", "Mobile Shop catalog/cart control did not render")
    cdp.evaluate("document.querySelector('[data-add-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '1'")
    cdp.evaluate("document.querySelector('[data-remove-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '0'")
    checks += 2

    primary_url = BASE + "img/illustration/balaam.jpg"
    hover_url = BASE + "img/graphic_design/Moon%20Silhoutte.jpg"
    v2_fields = {
        "sku": "TEST-UI-V2-" + token.upper(),
        "slug": "test-ui-v2-" + token,
        "title": "Browser V2 Compatibility Product",
        "shortDescription": "Short V2 copy",
        "description": "Full V2 copy before legacy edit.",
        "category": "Regression",
        "productType": "digital",
        "price": "20.00",
        "salePrice": "15.25",
        "stock": "4",
        "publicationStatus": "published",
        "storefrontVisible": "0",
        "showWhenSoldOut": "0",
        "featured": "1",
        "sortOrder": "777",
        "purchaseAction": "external",
        "externalUrl": "https://example.com/products/browser-regression",
        "images": json.dumps([
            {"path": primary_url, "altText": "Primary regression image", "sortOrder": 1},
            {"path": hover_url, "altText": "Hover regression image", "sortOrder": 2},
        ]),
        "manualBadges": json.dumps([{"label": "Limited", "sortOrder": 1}]),
    }
    status, created_v2 = api_request("product", "POST", v2_fields, session_id)
    expect(status == 201, "Could not create disposable V2 compatibility product")
    v2_id = created_v2["id"]
    fixture_ids.append(v2_id)
    preserved_before = normalized_preserved(created_v2["product"])

    cdp.call("Network.setCookie", {"name": "PHPSESSID", "value": session_id, "url": BASE})
    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
    })
    cdp.navigate(BASE + "admin.html")
    cdp.wait_for("!document.querySelector('#admin-content').hidden && document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    cdp.evaluate("window.__shopTestAlerts=[]; window.alert=(message)=>window.__shopTestAlerts.push(String(message)); window.confirm=()=>true")
    cdp.evaluate("document.querySelector('[data-admin-module=\"shop\"]').click()")
    cdp.wait_for("!document.querySelector('[data-admin-module-panel=\"shop\"]').hidden && document.querySelectorAll('#product-list .cms-product-row').length === 4")
    expect(cdp.evaluate("document.querySelectorAll('#product-list .cms-product-row').length") == 4, "Desktop Admin Product list did not contain three live products plus fixture")
    checks += 1

    cdp.evaluate("document.querySelector('[data-edit-product=\"1\"]').click()")
    live_form = cdp.evaluate("""
        (() => { const f=document.querySelector('#product-form'); return {
            id:f.elements.id.value, sku:f.elements.sku.value, title:f.elements.title.value,
            image:f.elements.image.value, description:f.elements.description.value,
            price:f.elements.price.value, stock:f.elements.stock.value,
            heading:document.querySelector('[data-product-form-title]').textContent,
            cancelHidden:document.querySelector('[data-cancel-product]').hidden
        }; })()
    """)
    expect(live_form["id"] == "1" and live_form["sku"] == initial_live[1]["sku"] and live_form["title"] == initial_live[1]["title"], "Edit did not populate the legacy form for a live product")
    expect(live_form["description"] == initial_live[1]["description"] and live_form["price"] == initial_live[1]["price"] and live_form["stock"] == str(initial_live[1]["stock"]), "Legacy edit fields were incomplete")
    expect(live_form["heading"] == "Edit product" and not live_form["cancelHidden"], "Edit mode controls did not activate")
    cdp.evaluate("document.querySelector('[data-cancel-product]').click()")
    expect(cdp.evaluate("document.querySelector('#product-form').elements.id.value === '' && document.querySelector('[data-cancel-product]').hidden"), "Cancel did not reset the product form")
    checks += 4

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    fixture_form = cdp.evaluate("""
        (() => { const f=document.querySelector('#product-form'); return {
            id:f.elements.id.value, sku:f.elements.sku.value, title:f.elements.title.value,
            slug:f.elements.slug.value, shortDescription:f.elements.shortDescription.value,
            image:f.elements.image.value, description:f.elements.description.value,
            price:f.elements.price.value, salePrice:f.elements.salePrice.value,
            productType:f.elements.productType.value, stock:f.elements.stock.value,
            publicationStatus:f.elements.publicationStatus.value,
            storefrontVisible:f.elements.storefrontVisible.checked,
            showWhenSoldOut:f.elements.showWhenSoldOut.checked,
            featured:f.elements.featured.checked, sortOrder:f.elements.sortOrder.value,
            purchaseAction:f.elements.purchaseAction.value, externalUrl:f.elements.externalUrl.value,
            badges:[...document.querySelectorAll('[data-product-badge]')].map(input => input.value),
            imageCount:document.querySelector('[data-product-image-count]').textContent,
            saveText:document.querySelector('[data-product-save]').textContent,
            toggleText:document.querySelector('[data-product-toggle-publication]').textContent,
            valid:f.checkValidity()
        }; })()
    """)
    expect(fixture_form["id"] == str(v2_id) and fixture_form["sku"] == v2_fields["sku"] and fixture_form["valid"], "Disposable V2 product did not populate a valid product edit form")
    expect(fixture_form["slug"] == v2_fields["slug"] and fixture_form["shortDescription"] == v2_fields["shortDescription"] and fixture_form["salePrice"] == v2_fields["salePrice"], "Basic or pricing fields were not populated")
    expect(fixture_form["productType"] == "digital" and fixture_form["publicationStatus"] == "published" and not fixture_form["storefrontVisible"] and not fixture_form["showWhenSoldOut"] and fixture_form["featured"], "Product type or publishing fields were not populated")
    expect(fixture_form["sortOrder"] == "777" and fixture_form["purchaseAction"] == "external" and fixture_form["externalUrl"] == v2_fields["externalUrl"], "Ordering or purchase fields were not populated")
    expect(fixture_form["badges"][0] == "Limited" and fixture_form["imageCount"] == "2 product images", "Badge or image-count information was not populated")
    expect(fixture_form["saveText"] == "Save Changes" and fixture_form["toggleText"] == "Unpublish", "Published product save actions were incorrect")
    checks += 5
    capture_screenshot(cdp, "shop-admin-b3-desktop.png")

    invalid_ux = cdp.evaluate("""
        (() => {
            const f=document.querySelector('#product-form');
            f.elements.salePrice.value=f.elements.price.value;
            document.querySelector('[data-product-badge]').value='Sale';
            f.requestSubmit();
            return {saleValid:f.elements.salePrice.validity.valid, badgeValid:document.querySelector('[data-product-badge]').validity.valid, id:f.elements.id.value};
        })()
    """)
    expect(not invalid_ux["saleValid"] and not invalid_ux["badgeValid"] and invalid_ux["id"] == str(v2_id), "Client validation did not block invalid sale/reserved badge values")
    cdp.evaluate(f"document.querySelector('#product-form').elements.salePrice.value='15.25'; document.querySelector('[data-product-badge]').value='Limited'; document.querySelector('#product-form').elements.description.value={js_string('Full V2 copy saved through the product editor.')} ; document.querySelector('#product-form').requestSubmit()")
    updated_description = "Full V2 copy saved through the product editor."
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === '' && document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    expect(cdp.evaluate("window.__shopTestAlerts.length") == 0, "V2 edit raised an Admin alert")
    status, after_edit_admin = api_request("admin-products", session_id=session_id)
    after_edit = next(product for product in after_edit_admin["products"] if product["id"] == v2_id)
    expect(after_edit["description"] == updated_description, "Edited description was not saved")
    expect(normalized_preserved(after_edit) == preserved_before, "Unchanged V2 fields or gallery data were reset")
    checks += 4

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    cdp.evaluate("""
        (() => {
            const f=document.querySelector('#product-form');
            f.elements.price.value='22.00';
            f.elements.salePrice.value='';
            f.elements.productType.value='physical';
            f.elements.storefrontVisible.checked=true;
            f.elements.showWhenSoldOut.checked=true;
            f.elements.featured.checked=false;
            f.elements.sortOrder.value='778';
            f.elements.purchaseAction.value='inquiry';
            f.elements.purchaseAction.dispatchEvent(new Event('change', {bubbles:true}));
            document.querySelector('[data-product-badge]').value='New';
            f.requestSubmit();
        })()
    """)
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, changed_admin = api_request("admin-products", session_id=session_id)
    changed = next(product for product in changed_admin["products"] if product["id"] == v2_id)
    expect(changed["id"] == v2_id and changed["sku"] == v2_fields["sku"], "Product identity changed during edit")
    expect(changed["regularPrice"] == "22.00" and changed["salePrice"] is None and changed["productType"] == "physical", "Price removal or product type edit failed")
    expect(changed["storefrontVisible"] and changed["showWhenSoldOut"] and not changed["featured"] and changed["sortOrder"] == 778, "Publishing controls did not save")
    expect(changed["purchaseAction"] == "inquiry" and changed["externalUrl"] is None and changed["manualBadges"][0]["label"] == "New", "Purchase-action or badge changes failed")
    expect(normalized_preserved(changed)["images"] == preserved_before["images"], "Editing B3 fields changed ordered gallery data")
    checks += 5

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click(); document.querySelector('#product-form').elements.salePrice.value='18.00'; document.querySelector('#product-form').requestSubmit()")
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, sale_admin = api_request("admin-products", session_id=session_id)
    sale_product = next(product for product in sale_admin["products"] if product["id"] == v2_id)
    expect(sale_product["salePrice"] == "18.00" and sale_product["currentPrice"] == "18.00", "Adding a sale price through Admin failed")
    checks += 1

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click(); document.querySelector('[data-product-toggle-publication]').click()")
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, draft_admin = api_request("admin-products", session_id=session_id)
    draft_product = next(product for product in draft_admin["products"] if product["id"] == v2_id)
    expect(draft_product["publicationStatus"] == "draft", "Unpublish action failed")
    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click(); document.querySelector('[data-product-toggle-publication]').click()")
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, republished_admin = api_request("admin-products", session_id=session_id)
    republished = next(product for product in republished_admin["products"] if product["id"] == v2_id)
    expect(republished["publicationStatus"] == "published", "Publish action failed")
    checks += 2

    legacy_sku = "TEST-UI-LEGACY-" + token.upper()
    legacy_title = "Browser Legacy Product " + token
    legacy_image = BASE + "img/illustration/balaam.jpg"
    cdp.evaluate(f"""
        (() => {{
            const f=document.querySelector('#product-form');
            f.elements.sku.value={js_string(legacy_sku)};
            f.elements.title.value={js_string(legacy_title)};
            f.elements.title.dispatchEvent(new Event('input', {{bubbles:true}}));
            const generated=f.elements.slug.value;
            f.elements.slug.value={js_string('browser-manual-product-' + token)};
            f.elements.slug.dispatchEvent(new Event('input', {{bubbles:true}}));
            f.elements.title.value='Changed ' + f.elements.title.value;
            f.elements.title.dispatchEvent(new Event('input', {{bubbles:true}}));
            window.__slugAssist={{generated, manual:f.elements.slug.value}};
            f.elements.image.value={js_string(legacy_image)};
            f.elements.description.value='Legacy browser form product.';
            f.elements.price.value='12.50';
            f.elements.stock.value='2';
            f.elements.productType.value='digital';
            f.requestSubmit();
        }})()
    """)
    cdp.wait_for("document.querySelectorAll('#product-list .cms-product-row').length === 5 && document.querySelector('#product-form').elements.sku.value === ''", 15)
    status, with_legacy = api_request("admin-products", session_id=session_id)
    legacy_product = next(product for product in with_legacy["products"] if product["sku"] == legacy_sku)
    legacy_id = legacy_product["id"]
    fixture_ids.append(legacy_id)
    expect(cdp.evaluate("window.__slugAssist.generated !== '' && window.__slugAssist.manual.startsWith('browser-manual-product-')"), "Slug assistance overwrote a manually edited slug")
    expect(legacy_product["publicationStatus"] == "draft" and legacy_product["productType"] == "digital" and legacy_product["category"] is None, "Draft creation defaults or optional category failed")
    expect(legacy_product["image"] == legacy_image and legacy_product["price"] == "12.50", "Draft create did not preserve submitted media or price")
    checks += 3

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{legacy_id}\"]').click(); document.querySelector('[data-cancel-product]').click()")
    expect(cdp.evaluate("document.querySelector('#product-form').elements.id.value === ''"), "Legacy create/edit cancel control failed")
    cdp.evaluate(f"document.querySelector('[data-delete-product=\"{legacy_id}\"]').click()")
    cdp.wait_for("document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    status, after_delete_admin = api_request("admin-products", session_id=session_id)
    expect(all(product["id"] != legacy_id for product in after_delete_admin["products"]), "Delete control did not remove the disposable legacy product")
    fixture_ids.remove(legacy_id)
    checks += 2

    published_sku = "TEST-UI-PUBLISHED-" + token.upper()
    published_title = "Published Browser Product " + token
    cdp.evaluate(f"""
        (() => {{
            const f=document.querySelector('#product-form');
            f.elements.sku.value={js_string(published_sku)};
            f.elements.title.value={js_string(published_title)};
            f.elements.title.dispatchEvent(new Event('input', {{bubbles:true}}));
            f.elements.shortDescription.value='Published short copy.';
            f.elements.description.value='Published product created through the B3 editor.';
            f.elements.category.value='Browser Tests';
            f.elements.image.value={js_string(legacy_image)};
            f.elements.price.value='30.00';
            f.elements.salePrice.value='25.00';
            f.elements.stock.value='1';
            f.elements.productType.value='physical';
            f.elements.purchaseAction.value='external';
            f.elements.purchaseAction.dispatchEvent(new Event('change', {{bubbles:true}}));
            f.elements.externalUrl.value='https://example.com/browser-product';
            document.querySelector('[data-product-badge]').value='Limited';
            document.querySelector('[data-product-toggle-publication]').click();
        }})()
    """)
    cdp.wait_for("document.querySelectorAll('#product-list .cms-product-row').length === 5 && document.querySelector('#product-form').elements.sku.value === ''", 15)
    status, with_published = api_request("admin-products", session_id=session_id)
    published_product = next(product for product in with_published["products"] if product["sku"] == published_sku)
    published_id = published_product["id"]
    fixture_ids.append(published_id)
    expect(published_product["publicationStatus"] == "published" and published_product["category"] == "Browser Tests" and published_product["productType"] == "physical", "Published product creation fields failed")
    expect(published_product["salePrice"] == "25.00" and published_product["purchaseAction"] == "external" and published_product["externalUrl"] == "https://example.com/browser-product", "Published sale/external configuration failed")
    expect(published_product["manualBadges"][0]["label"] == "Limited", "Manual badge creation failed")
    cdp.evaluate(f"document.querySelector('[data-delete-product=\"{published_id}\"]').click()")
    cdp.wait_for("document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    fixture_ids.remove(published_id)
    checks += 4

    status, projects = api_request("projects")
    expect(status == 200, "Projects API failed during browser regression")
    cdp.evaluate("document.querySelector('[data-admin-module=\"projects\"]').click()")
    cdp.wait_for("!document.querySelector('[data-admin-module-panel=\"projects\"]').hidden")
    expect(cdp.evaluate("document.querySelectorAll('#project-list .project-item').length") == len(projects["projects"]), "Projects Admin list did not match its API")
    status, content = api_request("admin-content", session_id=session_id)
    expect(status == 200, "Content Admin API failed during browser regression")
    cdp.evaluate("document.querySelector('[data-admin-module=\"content\"]').click()")
    cdp.wait_for("!document.querySelector('[data-admin-module-panel=\"content\"]').hidden")
    content_dom_count = cdp.evaluate("document.querySelectorAll('#content-list .cms-content-row').length")
    expect(content_dom_count == len(content["entries"]), "Content Admin list did not match its API")

    status, theme = api_request("theme")
    expect(status == 200 and theme.get("theme"), "Theme API failed during browser regression")
    cdp.evaluate("document.querySelector('[data-admin-module=\"theme\"]').click()")
    cdp.wait_for("!document.querySelector('[data-admin-module-panel=\"theme\"]').hidden")
    theme_state = cdp.evaluate("""
        (() => {
            const form=document.querySelector('#theme-form');
            form.requestSubmit();
            return {
                accent:form.elements.accent.value,
                previewCards:document.querySelectorAll('[data-gallery-theme-preview] .cms-gallery-preview-card').length,
                publishEnabled:!document.querySelector('[data-theme-publish]').disabled
            };
        })()
    """)
    expect(theme_state["accent"].lower() == theme["theme"]["accentColor"].lower() and theme_state["previewCards"] > 0 and theme_state["publishEnabled"], "Theme controls did not load or preview correctly")

    cdp.evaluate("document.querySelector('[data-admin-module=\"settings\"]').click()")
    cdp.wait_for("!document.querySelector('[data-admin-module-panel=\"settings\"]').hidden")
    settings_state = cdp.evaluate("""
        (() => {
            const form=document.querySelector('#social-form');
            form.elements.instagram.value='https://instagram.com/dyndel-browser-test';
            form.requestSubmit();
            const saved=JSON.parse(localStorage.getItem('dyndelSocials') || '{}');
            return {
                fieldCount:form.querySelectorAll('input[type="url"]').length,
                savedInstagram:saved.instagram || ''
            };
        })()
    """)
    expect(settings_state == {"fieldCount": 4, "savedInstagram": "https://instagram.com/dyndel-browser-test"}, "Settings social-link form did not save in the disposable browser profile")
    checks += 8

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.navigate(BASE + "admin.html")
    cdp.wait_for("!document.querySelector('#admin-content').hidden && document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    cdp.evaluate("document.querySelector('[data-admin-module=\"shop\"]').click()")
    mobile_admin = cdp.evaluate("({products:document.querySelectorAll('#product-list .cms-product-row').length, panelHidden:document.querySelector('[data-admin-module-panel=\"shop\"]').hidden, width:innerWidth})")
    expect(mobile_admin == {"products": 4, "panelHidden": False, "width": 390}, "Mobile Admin Shop regression failed")
    checks += 1
    capture_screenshot(cdp, "shop-admin-b3-mobile.png")

    expect(not cdp.runtime_errors, "Browser JavaScript errors occurred: " + "; ".join(cdp.runtime_errors))
    checks += 1
finally:
    for product_id in list(fixture_ids):
        try:
            api_request("delete-product", "POST", {"id": product_id}, session_id)
        except Exception:
            pass
    for table, next_id in initial_auto_increments.items():
        if table.replace("_", "").isalnum() and next_id.isdigit():
            subprocess.run([
                MYSQL, "--host=127.0.0.1", "--user=root", "--database=dyndel_portfolio",
                f"--execute=ALTER TABLE `{table}` AUTO_INCREMENT = {next_id};",
            ], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        cleanup_status, cleanup_admin = api_request("admin-products", session_id=session_id)
        cleanup_live = {product["id"]: product for product in cleanup_admin.get("products", [])}
        expect(cleanup_status == 200 and cleanup_live == initial_live, "Live product state changed during browser regression")
    except Exception:
        raise
    try:
        api_request("logout", "POST", {}, session_id)
    except Exception:
        pass
    if cdp is not None:
        cdp.close()
    if chrome is not None:
        chrome.terminate()
        try:
            chrome.wait(timeout=10)
        except subprocess.TimeoutExpired:
            chrome.kill()
    profile_path = os.path.abspath(profile)
    temp_path = os.path.abspath(tempfile.gettempdir())
    if os.path.commonpath([profile_path, temp_path]) == temp_path and os.path.basename(profile_path).startswith("dyndel-shop-browser-"):
        shutil.rmtree(profile_path, ignore_errors=True)

status, final_admin = api_request("admin-products", session_id=None)
expect(status == 401, "Browser test session remained authenticated after logout")

subprocess.run([
    PHP,
    "-r",
    f'session_id("{session_id}"); session_start(); session_destroy();',
], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

print(f"Shop UI browser regression passed: {checks} assertions at desktop and mobile widths.")
