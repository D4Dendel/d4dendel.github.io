import base64
import hashlib
import json
import os
import re
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
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHOP_MEDIA_DIR = os.path.join(ROOT, "img", "projectfolder", "shop")


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


def multipart_request(action, fields, file_name, file_bytes, content_type, session_id=None):
    boundary = "----DyndelShopTest" + secrets.token_hex(12)
    chunks = []
    for name, value in fields.items():
        chunks.extend([
            f"--{boundary}\r\n".encode(),
            f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode(),
            str(value).encode(), b"\r\n",
        ])
    chunks.extend([
        f"--{boundary}\r\n".encode(),
        f'Content-Disposition: form-data; name="imageFile"; filename="{file_name}"\r\n'.encode(),
        f"Content-Type: {content_type}\r\n\r\n".encode(),
        file_bytes, b"\r\n", f"--{boundary}--\r\n".encode(),
    ])
    request = urllib.request.Request(
        API + "?action=" + urllib.parse.quote(action),
        data=b"".join(chunks),
        method="POST",
        headers={"Content-Type": f"multipart/form-data; boundary={boundary}"},
    )
    if session_id:
        request.add_header("Cookie", "PHPSESSID=" + session_id)
    try:
        response = urllib.request.urlopen(request, timeout=30)
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


def set_file_input(cdp, selector, path):
    document = cdp.call("DOM.getDocument")
    node = cdp.call("DOM.querySelector", {"nodeId": document["root"]["nodeId"], "selector": selector})
    expect(node.get("nodeId"), "Could not find file input: " + selector)
    cdp.call("DOM.setFileInputFiles", {"nodeId": node["nodeId"], "files": [path]})


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


def normalized_non_media(product):
    preserved = normalized_preserved(product)
    preserved.pop("images")
    return preserved


checks = 0
token = secrets.token_hex(4)
session_id = "shopuibrowser" + token
fixture_ids = []
uploaded_test_paths = []
chrome = None
cdp = None
profile = tempfile.mkdtemp(prefix="dyndel-shop-browser-")
source_image = tempfile.NamedTemporaryFile(prefix="dyndel-shop-upload-", suffix=".png", delete=False)
source_image.write(base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="))
source_image.close()

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
status, initial_shop_response = api_request("shop")
expect(status == 200 and len(initial_shop_response.get("products", [])) == 3, "Could not capture the initial public Shop")
initial_public_products = initial_shop_response["products"]

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
    cdp.wait_for("[...document.querySelectorAll('.shop-product-image.is-primary')].every(image => image.complete && image.naturalWidth > 0)", 15)
    desktop_cards = cdp.evaluate("""
        [...document.querySelectorAll('.shop-product')].map(card => ({
            image: card.querySelector('.shop-product-image.is-primary')?.getAttribute('src') || '',
            imageLoaded: Boolean(card.querySelector('.shop-product-image.is-primary')?.complete && card.querySelector('.shop-product-image.is-primary')?.naturalWidth),
            title: card.querySelector('h3')?.textContent.trim() || '',
            price: card.querySelector('.shop-product-price')?.textContent.trim() || '',
            href: card.querySelector('.shop-product-link')?.getAttribute('href') || '',
            description: Boolean(card.querySelector('.shop-product-copy > p:not(.shop-product-price)')),
            addButtons: card.querySelectorAll('[data-add-cart]').length
        }))
    """)
    expect(len(desktop_cards) == 3, "Desktop Shop did not render three cards")
    expect(all(card["image"] and card["imageLoaded"] and card["title"] and card["price"] and card["href"] for card in desktop_cards), "A storefront card was missing artwork, title, price, or link")
    expect(all(not card["description"] and card["addButtons"] == 0 for card in desktop_cards), "A storefront card still exposed description or Add to Cart")
    storefront_shell = cdp.evaluate("""
        (() => {
            const banner=document.querySelector('.shop-banner');
            const header=document.querySelector('.header');
            const card=document.querySelector('.shop-product');
            const link=card.querySelector('.shop-product-link');
            const title=card.querySelector('h3').getBoundingClientRect();
            const price=card.querySelector('.shop-product-price').getBoundingClientRect();
            const cardRect=card.getBoundingClientRect();
            const linkRect=link.getBoundingClientRect();
            const cardStyle=getComputedStyle(card);
            const bannerRect=banner.getBoundingClientRect();
            return {
                bannerHeight:bannerRect.height,
                bannerLeft:bannerRect.left,
                bannerRight:bannerRect.right,
                viewportWidth:document.documentElement.clientWidth,
                bannerHeaderGap:bannerRect.top-header.getBoundingClientRect().bottom,
                bannerImages:document.querySelectorAll('[data-shop-banner-art] img').length,
                headings:[...document.querySelectorAll('.shop-collection > h2')].map(item => item.textContent),
                columns:getComputedStyle(document.querySelector('.shop-grid')).gridTemplateColumns.split(' ').length,
                titlePriceGap:price.top-title.bottom,
                cardRadius:parseFloat(cardStyle.borderTopLeftRadius),
                cardSurface:cardStyle.backgroundColor,
                linkCoversCard:Math.abs(linkRect.width-cardRect.width) <= 2 && Math.abs(linkRect.height-cardRect.height) <= 2,
                cartInHeader:Boolean(document.querySelector('.header [data-open-cart]')),
                cartWidth:document.querySelector('[data-open-cart]').getBoundingClientRect().width,
                emptyCountHidden:document.querySelector('[data-cart-count]').hidden,
                overflow:document.documentElement.scrollWidth > innerWidth
            };
        })()
    """)
    expect(storefront_shell["bannerHeight"] <= 300 and storefront_shell["bannerImages"] == 3 and abs(storefront_shell["bannerLeft"]) < 1 and abs(storefront_shell["bannerRight"] - storefront_shell["viewportWidth"]) < 1 and abs(storefront_shell["bannerHeaderGap"]) < 1, "Desktop Shop banner was not full-bleed, flush to the header, short, and artwork-led: " + json.dumps(storefront_shell))
    expect(storefront_shell["headings"] == ["Art Prints"] and storefront_shell["columns"] == 3 and not storefront_shell["overflow"], "Desktop collection/grid layout was incorrect")
    expect(0 <= storefront_shell["titlePriceGap"] <= 8 and storefront_shell["cardRadius"] >= 10 and storefront_shell["cardSurface"] != "rgba(0, 0, 0, 0)" and storefront_shell["linkCoversCard"], "Desktop product cards were not compact, rounded, surfaced, and fully linked")
    expect(storefront_shell["cartInHeader"] and storefront_shell["cartWidth"] < 100 and storefront_shell["emptyCountHidden"], "Desktop cart access was not compactly integrated with the header")
    status, theme_response = api_request("theme")
    applied_accent = cdp.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--blue-deep').trim().toLowerCase()")
    expect(status == 200 and applied_accent == theme_response["theme"]["accentColor"].lower(), "Public Shop did not apply the published theme")
    checks += 8

    first_product = initial_public_products[0]
    first_price = float(first_product["currentPrice"])
    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', JSON.stringify([{{id:{first_product['id']},quantity:2}}])); location.reload()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '2'")
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("!document.querySelector('[data-cart-region]').hidden && !document.querySelector('[data-cart-panel]').hidden")
    cart_state = cdp.evaluate("""
        (() => {
            const panel=document.querySelector('[data-cart-panel]').getBoundingClientRect();
            const catalog=document.querySelector('.shop-catalog').getBoundingClientRect();
            const count=document.querySelector('[data-cart-count]');
            return {count:count.textContent, countHidden:count.hidden, total:document.querySelector('[data-cart-total]').textContent, item:document.querySelector('.shop-cart-item span').textContent, expanded:document.querySelector('[data-open-cart]').getAttribute('aria-expanded'), panelBeforeCatalog:panel.bottom <= catalog.top};
        })()
    """)
    expect(cart_state["total"] == f"${first_price * 2:.2f}" and "× 2" in cart_state["item"] and cart_state["count"] == "2" and not cart_state["countHidden"], "Desktop cart quantity, count, or total changed")
    expect(cart_state["expanded"] == "true" and cart_state["panelBeforeCatalog"], "Opened cart did not remain in document flow above the product cards")
    cdp.evaluate("document.querySelector('[data-remove-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '0'")
    expect(cdp.evaluate("document.querySelector('[data-cart-total]').textContent === '$0.00' && document.querySelector('[data-cart-count]').hidden") is True, "Desktop cart removal did not reset the total and hide the empty count")
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden")
    checks += 3

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.navigate(BASE + "graphic-design.html", "document.readyState === 'complete' && document.querySelectorAll('.shop-product').length === 3")
    mobile_state = cdp.evaluate("""
        (() => {
            const banner=document.querySelector('.shop-banner').getBoundingClientRect();
            const header=document.querySelector('.header').getBoundingClientRect();
            const card=document.querySelector('.shop-product');
            const cardRect=card.getBoundingClientRect();
            const title=card.querySelector('h3').getBoundingClientRect();
            const price=card.querySelector('.shop-product-price').getBoundingClientRect();
            const cart=document.querySelector('[data-open-cart]');
            const cartRect=cart.getBoundingClientRect();
            const mascot=document.querySelector('.contact-mascot')?.getBoundingClientRect();
            const overlaps=(a,b) => Boolean(a && b && a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top);
            return {
                cards:document.querySelectorAll('.shop-product').length,
                cartDisplay:getComputedStyle(cart).display,
                cartInHeader:Boolean(cart.closest('.header')),
                cartWidth:cartRect.width,
                cartOverlap:overlaps(cartRect,cardRect) || overlaps(cartRect,mascot),
                columns:getComputedStyle(document.querySelector('.shop-grid')).gridTemplateColumns.split(' ').length,
                bannerHeight:banner.height,
                bannerEdges:Math.abs(banner.left) < 1 && Math.abs(banner.right-innerWidth) < 1,
                bannerHeaderGap:banner.top-header.bottom,
                titlePriceGap:price.top-title.bottom,
                cardRadius:parseFloat(getComputedStyle(card).borderTopLeftRadius),
                addButtons:document.querySelectorAll('[data-add-cart]').length,
                overflow:document.documentElement.scrollWidth > innerWidth,
                navToggle:getComputedStyle(document.querySelector('.menu-toggle')).display
            };
        })()
    """)
    expect(mobile_state["cards"] == 3 and mobile_state["cartDisplay"] != "none" and mobile_state["cartInHeader"] and mobile_state["cartWidth"] < 100 and not mobile_state["cartOverlap"] and mobile_state["navToggle"] != "none", "Mobile Shop catalog, compact cart access, or navigation did not render without overlap")
    expect(mobile_state["columns"] == 2 and mobile_state["bannerHeight"] <= 220 and mobile_state["bannerEdges"] and abs(mobile_state["bannerHeaderGap"]) < 1 and mobile_state["addButtons"] == 0 and not mobile_state["overflow"], "Mobile full-bleed banner/grid layout regressed")
    expect(0 <= mobile_state["titlePriceGap"] <= 8 and mobile_state["cardRadius"] >= 10, "Mobile card rounding or title/price spacing regressed")
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    expect(cdp.evaluate("document.querySelector('.nav').classList.contains('open')") is True, "Mobile navigation toggle did not open")
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    checks += 4

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
            imageCount:document.querySelectorAll('[data-product-image-list] .cms-product-image-item').length,
            primaryPath:document.querySelector('.cms-product-image-path')?.textContent || '', description:f.elements.description.value,
            price:f.elements.price.value, stock:f.elements.stock.value,
            heading:document.querySelector('[data-product-form-title]').textContent,
            cancelHidden:document.querySelector('[data-cancel-product]').hidden
        }; })()
    """)
    expect(live_form["id"] == "1" and live_form["sku"] == initial_live[1]["sku"] and live_form["title"] == initial_live[1]["title"], "Edit did not populate the legacy form for a live product")
    expect(live_form["description"] == initial_live[1]["description"] and live_form["price"] == initial_live[1]["price"] and live_form["stock"] == str(initial_live[1]["stock"]), "Legacy edit fields were incomplete")
    expect(live_form["imageCount"] == 1 and live_form["primaryPath"] == initial_live[1]["images"][0]["path"], "Existing live product image did not populate the gallery")
    expect(live_form["heading"] == "Edit product" and not live_form["cancelHidden"], "Edit mode controls did not activate")
    cdp.evaluate("document.querySelector('[data-cancel-product]').click()")
    expect(cdp.evaluate("document.querySelector('#product-form').elements.id.value === '' && document.querySelector('[data-cancel-product]').hidden"), "Cancel did not reset the product form")
    expect(cdp.evaluate("document.querySelector('[data-product-upload]').disabled && document.querySelector('[data-product-upload-help]').textContent.includes('draft')"), "New-product upload limitation was not explained")
    checks += 6

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    fixture_form = cdp.evaluate("""
        (() => { const f=document.querySelector('#product-form'); return {
            id:f.elements.id.value, sku:f.elements.sku.value, title:f.elements.title.value,
            slug:f.elements.slug.value, shortDescription:f.elements.shortDescription.value,
            description:f.elements.description.value,
            price:f.elements.price.value, salePrice:f.elements.salePrice.value,
            productType:f.elements.productType.value, stock:f.elements.stock.value,
            publicationStatus:f.elements.publicationStatus.value,
            storefrontVisible:f.elements.storefrontVisible.checked,
            showWhenSoldOut:f.elements.showWhenSoldOut.checked,
            featured:f.elements.featured.checked, sortOrder:f.elements.sortOrder.value,
            purchaseAction:f.elements.purchaseAction.value, externalUrl:f.elements.externalUrl.value,
            badges:[...document.querySelectorAll('[data-product-badge]')].map(input => input.value),
            imageCount:document.querySelectorAll('[data-product-image-list] .cms-product-image-item').length,
            primaryRole:document.querySelector('.cms-product-image-role')?.textContent || '',
            saveText:document.querySelector('[data-product-save]').textContent,
            toggleText:document.querySelector('[data-product-toggle-publication]').textContent,
            valid:f.checkValidity()
        }; })()
    """)
    expect(fixture_form["id"] == str(v2_id) and fixture_form["sku"] == v2_fields["sku"] and fixture_form["valid"], "Disposable V2 product did not populate a valid product edit form")
    expect(fixture_form["slug"] == v2_fields["slug"] and fixture_form["shortDescription"] == v2_fields["shortDescription"] and fixture_form["salePrice"] == v2_fields["salePrice"], "Basic or pricing fields were not populated")
    expect(fixture_form["productType"] == "digital" and fixture_form["publicationStatus"] == "published" and not fixture_form["storefrontVisible"] and not fixture_form["showWhenSoldOut"] and fixture_form["featured"], "Product type or publishing fields were not populated")
    expect(fixture_form["sortOrder"] == "777" and fixture_form["purchaseAction"] == "external" and fixture_form["externalUrl"] == v2_fields["externalUrl"], "Ordering or purchase fields were not populated")
    expect(fixture_form["badges"][0] == "Limited" and fixture_form["imageCount"] == 1 and fixture_form["primaryRole"] == "Primary", "Badge or gallery information was not populated")
    expect(fixture_form["saveText"] == "Save Changes" and fixture_form["toggleText"] == "Unpublish", "Published product save actions were incorrect")
    checks += 5

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

    with open(source_image.name, "rb") as image_source:
        valid_png = image_source.read()
    status, _ = multipart_request("product-image-upload", {"id": v2_id}, "unauth.png", valid_png, "image/png")
    expect(status == 401, "Unauthenticated media upload was not rejected")
    status, _ = multipart_request("product-image-upload", {"id": v2_id}, "not-image.txt", b"not an image", "text/plain", session_id)
    expect(status == 422, "Unsupported upload was not rejected")
    status, _ = multipart_request("product-image-upload", {"id": v2_id}, "oversized.png", b"0" * (8 * 1024 * 1024 + 1), "image/png", session_id)
    expect(status == 422, "Oversized upload was not rejected")
    checks += 3

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click(); document.querySelector('[data-product-image-url]').value={js_string(hover_url)}; document.querySelector('[data-product-add-url]').click()")
    expect(cdp.evaluate("document.querySelectorAll('[data-product-image-list] .cms-product-image-item').length") == 2, "Second image was not added in Admin")
    cdp.evaluate("document.querySelector('#product-form').requestSubmit()")
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, second_admin = api_request("admin-products", session_id=session_id)
    second_product = next(product for product in second_admin["products"] if product["id"] == v2_id)
    expect([image["path"] for image in second_product["images"]] == [primary_url, hover_url], "Second image did not persist in order")
    checks += 2

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    set_file_input(cdp, "[data-product-upload-input]", source_image.name)
    cdp.evaluate("document.querySelector('[data-product-upload]').click()")
    cdp.wait_for("document.querySelectorAll('[data-product-image-list] .cms-product-image-item').length === 3 && document.querySelector('[data-product-form-message]').textContent.includes('uploaded')", 20)
    status, uploaded_admin = api_request("admin-products", session_id=session_id)
    uploaded_product = next(product for product in uploaded_admin["products"] if product["id"] == v2_id)
    uploaded_path = uploaded_product["images"][2]["path"]
    uploaded_test_paths.append(uploaded_path)
    expect(uploaded_path.startswith("img/projectfolder/shop/") and len(uploaded_product["images"]) == 3, "Uploaded third image was not stored in the Shop media directory")
    expect(normalized_non_media(uploaded_product) == normalized_non_media(second_product), "Image upload altered non-media product fields")
    preserved_before_gallery = normalized_non_media(uploaded_product)

    cdp.evaluate("""
        (() => {
            document.querySelector('[aria-label="Move image 3 up"]').click();
            document.querySelector('[aria-label="Move image 2 up"]').click();
            const firstAlt=document.querySelector('.cms-product-image-item input[type="text"]');
            firstAlt.value='Uploaded primary alt text';
            firstAlt.dispatchEvent(new Event('input', {bubbles:true}));
            document.querySelector('[aria-label="Remove image 2 from product"]').click();
            document.querySelector('#product-form').requestSubmit();
        })()
    """)
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, gallery_admin = api_request("admin-products", session_id=session_id)
    gallery_product = next(product for product in gallery_admin["products"] if product["id"] == v2_id)
    expect([image["path"] for image in gallery_product["images"]] == [uploaded_path, hover_url], "Gallery reorder or middle-image removal failed")
    expect([image["sortOrder"] for image in gallery_product["images"]] == [1, 2] and gallery_product["images"][0]["altText"] == "Uploaded primary alt text", "Gallery positions or alt text did not persist")
    expect(gallery_product["image"] == uploaded_path, "Legacy image_url did not follow the reordered primary image")
    expect(normalized_non_media(gallery_product) == preserved_before_gallery, "Gallery operations changed non-media product fields")
    checks += 6

    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    reload_gallery = cdp.evaluate("""
        [...document.querySelectorAll('.cms-product-image-item')].map(item => ({
            role:item.querySelector('.cms-product-image-role').textContent,
            path:item.querySelector('.cms-product-image-path').textContent,
            alt:item.querySelector('input[type="text"]').value
        }))
    """)
    expect(reload_gallery == [
        {"role": "Primary", "path": uploaded_path, "alt": "Uploaded primary alt text"},
        {"role": "Image 2 · future hover", "path": hover_url, "alt": ""},
    ], "Saved gallery did not reload exactly")
    capture_screenshot(cdp, "shop-admin-b4-desktop.png")

    storefront_fields = {
        "sku": "TEST-UI-STOREFRONT-" + token.upper(),
        "slug": "test-ui-storefront-" + token,
        "title": "A Long Limited Studio Print for Storefront Regression",
        "shortDescription": "Disposable storefront fixture.",
        "description": "Disposable storefront fixture for sold-out and external-link behavior.",
        "category": "",
        "productType": "physical",
        "price": "10.00",
        "salePrice": "8.00",
        "stock": "0",
        "publicationStatus": "published",
        "storefrontVisible": "1",
        "showWhenSoldOut": "1",
        "featured": "1",
        "sortOrder": "999",
        "purchaseAction": "external",
        "externalUrl": "https://example.com/storefront-regression",
        "images": json.dumps([
            {"path": primary_url, "altText": "Storefront primary artwork", "sortOrder": 1},
            {"path": hover_url, "altText": "Storefront hover artwork", "sortOrder": 2},
        ]),
        "manualBadges": json.dumps([{"label": "Limited", "sortOrder": 1}]),
    }
    status, storefront_created = api_request("product", "POST", storefront_fields, session_id)
    expect(status == 201, "Could not create disposable storefront fixture")
    storefront_id = storefront_created["id"]
    fixture_ids.append(storefront_id)

    cdp.navigate(BASE + "graphic-design.html")
    cdp.wait_for("document.querySelectorAll('.shop-product').length === 5", 15)
    storefront_state = cdp.evaluate(f"""
        (() => {{
            const inquiry=document.querySelector('[data-product-id="{v2_id}"]');
            const external=document.querySelector('[data-product-id="{storefront_id}"]');
            const externalBadges=[...external.querySelectorAll('.shop-product-badge')].map(item => item.textContent);
            return {{
                cards:document.querySelectorAll('.shop-product').length,
                headings:[...document.querySelectorAll('.shop-collection > h2')].map(item => item.textContent),
                inquiryHref:inquiry.querySelector('.shop-product-link').getAttribute('href'),
                inquiryPrimary:inquiry.querySelector('.is-primary').getAttribute('src'),
                inquiryAlt:inquiry.querySelector('.is-primary').alt,
                inquiryImages:inquiry.querySelectorAll('.shop-product-image').length,
                inquiryPrice:inquiry.querySelector('.shop-product-price').textContent.replace(/\s+/g, ' ').trim(),
                inquiryBadges:[...inquiry.querySelectorAll('.shop-product-badge')].map(item => item.textContent),
                externalHref:external.querySelector('.shop-product-link').getAttribute('href'),
                externalImages:external.querySelectorAll('.shop-product-image').length,
                externalBadges,
                badgesInsideImage:[...external.querySelectorAll('.shop-product-badge')].every(item => item.closest('.shop-product-media')),
                soldOut:external.classList.contains('is-sold-out'),
                addButtons:document.querySelectorAll('[data-add-cart]').length,
                internalHook:document.querySelector('[data-product-id="1"] .shop-product-link').getAttribute('href'),
                overflow:document.documentElement.scrollWidth > innerWidth
            }};
        }})()
    """)
    expect(storefront_state["cards"] == 5 and storefront_state["headings"] == ["Art Prints", "Regression", "More from the studio"], "Category and uncategorized storefront sections were incorrect")
    expect(storefront_state["inquiryHref"] == "index.html#contact" and storefront_state["externalHref"] == storefront_fields["externalUrl"] and storefront_state["internalHook"].startswith("graphic-design.html?product="), "Product action links were unsafe or incorrect")
    expect(storefront_state["inquiryPrimary"] == uploaded_path and storefront_state["inquiryAlt"] == "Uploaded primary alt text" and storefront_state["inquiryImages"] == 2, "Ordered gallery or primary alt text was not used by the storefront")
    expect("$18.00" in storefront_state["inquiryPrice"] and "$22.00" in storefront_state["inquiryPrice"] and storefront_state["inquiryBadges"] == ["Sale", "New"], "Sale price or minimal inquiry badges were incorrect")
    expect(storefront_state["externalImages"] == 2 and storefront_state["externalBadges"] == ["Sale", "Sold Out", "Limited"] and storefront_state["badgesInsideImage"] and storefront_state["soldOut"], "Sold-out, hover-image, or badge presentation was incorrect")
    expect(storefront_state["addButtons"] == 0 and not storefront_state["overflow"], "Storefront retained transactional buttons or overflowed")

    cdp.call("Page.bringToFront")
    focus_state = cdp.evaluate(f"""
        (async () => {{
            const card=document.querySelector('[data-product-id="{storefront_id}"]');
            const badge=card.querySelector('.shop-product-badge').getBoundingClientRect();
            const link=card.querySelector('.shop-product-link');
            link.focus({{preventScroll:true}});
            await new Promise(resolve => setTimeout(resolve, 700));
            const badgeAfter=card.querySelector('.shop-product-badge').getBoundingClientRect();
            return {{
                secondaryOpacity:Number(getComputedStyle(card.querySelector('.is-secondary')).opacity),
                primaryOpacity:Number(getComputedStyle(card.querySelector('.is-primary')).opacity),
                badgeStationary:badge.x === badgeAfter.x && badge.y === badgeAfter.y,
                outline:getComputedStyle(link).outlineStyle,
                active:document.activeElement === link,
                matchesFocus:link.matches(':focus')
            }};
        }})()
    """, await_promise=True)
    expect(focus_state["active"] and focus_state["matchesFocus"] and focus_state["secondaryOpacity"] >= 0.8 and focus_state["primaryOpacity"] < 0.02 and focus_state["badgeStationary"] and focus_state["outline"] != "none", "Keyboard focus did not expose the hover image with a stable badge and visible focus: " + json.dumps(focus_state))
    cdp.call("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-reduced-motion", "value": "reduce"}]})
    expect(cdp.evaluate(f"getComputedStyle(document.querySelector('[data-product-id=\"{storefront_id}\"] .is-secondary')).transitionDuration") == "0s", "Reduced-motion preference did not disable the image transition")
    cdp.call("Emulation.setEmulatedMedia", {"features": []})
    capture_screenshot(cdp, "shop-c-desktop.png")
    checks += 9

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    mobile_storefront = cdp.evaluate(f"""
        (async () => {{
            const card=document.querySelector('[data-product-id="{storefront_id}"]');
            card.querySelector('.shop-product-link').blur();
            await new Promise(resolve => setTimeout(resolve, 700));
            return {{
                columns:getComputedStyle(document.querySelector('.shop-grid')).gridTemplateColumns.split(' ').length,
                bannerHeight:document.querySelector('.shop-banner').getBoundingClientRect().height,
                primaryOpacity:Number(getComputedStyle(card.querySelector('.is-primary')).opacity),
                overflow:document.documentElement.scrollWidth > innerWidth,
                longTitleHeight:card.querySelector('h3').getBoundingClientRect().height
            }};
        }})()
    """, await_promise=True)
    expect(mobile_storefront["columns"] == 2 and mobile_storefront["bannerHeight"] <= 220 and mobile_storefront["primaryOpacity"] > 0.8 and not mobile_storefront["overflow"] and mobile_storefront["longTitleHeight"] > 30, "Mobile storefront banner, grid, title, or primary image regressed: " + json.dumps(mobile_storefront))
    capture_screenshot(cdp, "shop-c-mobile.png")
    checks += 1

    status, _ = api_request("delete-product", "POST", {"id": storefront_id}, session_id)
    expect(status == 200, "Could not remove disposable storefront fixture")
    fixture_ids.remove(storefront_id)
    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
    })

    cdp.navigate(BASE + "admin.html")
    cdp.wait_for("!document.querySelector('#admin-content').hidden && document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    cdp.evaluate("window.__shopTestAlerts=[]; window.alert=(message)=>window.__shopTestAlerts.push(String(message)); window.confirm=()=>true")
    cdp.evaluate("document.querySelector('[data-admin-module=\"shop\"]').click()")
    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click(); document.querySelector('[aria-label=\"Remove image 1 from product\"]').click(); document.querySelector('#product-form').requestSubmit()")
    cdp.wait_for("document.querySelector('#product-form').elements.id.value === ''", 15)
    status, removed_admin = api_request("admin-products", session_id=session_id)
    removed_product = next(product for product in removed_admin["products"] if product["id"] == v2_id)
    uploaded_disk_path = os.path.join(ROOT, *uploaded_path.split("/"))
    expect([image["path"] for image in removed_product["images"]] == [hover_url], "Uploaded image relationship was not removed")
    expect(os.path.isfile(uploaded_disk_path), "Removing an image relationship deleted its physical media file")
    expect(normalized_non_media(removed_product) == preserved_before_gallery, "Image removal changed non-media product fields")
    checks += 3

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
            document.querySelector('[data-product-image-url]').value={js_string(legacy_image)};
            document.querySelector('[data-product-add-url]').click();
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
            document.querySelector('[data-product-image-url]').value={js_string(legacy_image)};
            document.querySelector('[data-product-add-url]').click();
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

    illustration_count = len([project for project in projects["projects"] if project["category"] == "Illustration"])
    cdp.navigate(BASE + "illustration.html")
    cdp.wait_for(f"document.querySelectorAll('[data-category-projects] .gallery-card').length === {illustration_count}", 15)
    expect(cdp.evaluate("document.querySelectorAll('[data-category-projects] .gallery-card').length") == illustration_count, "Public Projects gallery did not match its API")

    status, public_content = api_request("content")
    expect(status == 200 and public_content.get("entries"), "No published Story was available for regression")
    published_story = public_content["entries"][0]
    cdp.navigate(BASE + "stories.php")
    cdp.wait_for("document.querySelector('[data-stories-results]').getAttribute('aria-busy') === 'false'", 15)
    expect(cdp.evaluate("document.querySelectorAll('[data-stories-list] .story-card').length") == len(public_content["entries"]), "Stories archive did not render published entries")
    cdp.navigate(BASE + "story.php?slug=" + urllib.parse.quote(published_story["slug"]))
    expect(cdp.evaluate("document.querySelector('.story-article h1')?.textContent || ''") == published_story["title"], "Story reader did not render the selected published story")

    cdp.navigate(BASE + "index.html#contact")
    cdp.wait_for("location.hash === '#contact' && document.querySelector('#contact') && document.querySelectorAll('.home-gallery .gallery-card').length > 0", 15)
    contact_state = cdp.evaluate("""
        (() => {
            const contact=document.querySelector('#contact').getBoundingClientRect();
            const header=document.querySelector('.header').getBoundingClientRect();
            return {hash:location.hash, contactVisible:contact.top >= header.height - 6 && contact.top < innerHeight, projects:document.querySelectorAll('.home-gallery .gallery-card').length};
        })()
    """)
    expect(contact_state["hash"] == "#contact" and contact_state["contactVisible"] and contact_state["projects"] > 0, "Contact deep link or homepage project gallery regressed")
    checks += 5

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.navigate(BASE + "admin.html")
    cdp.wait_for("!document.querySelector('#admin-content').hidden && document.querySelectorAll('#product-list .cms-product-row').length === 4", 15)
    cdp.evaluate("document.querySelector('[data-admin-module=\"shop\"]').click()")
    cdp.evaluate(f"document.querySelector('[data-edit-product=\"{v2_id}\"]').click()")
    mobile_admin = cdp.evaluate("({products:document.querySelectorAll('#product-list .cms-product-row').length, panelHidden:document.querySelector('[data-admin-module-panel=\"shop\"]').hidden, width:innerWidth, images:document.querySelectorAll('.cms-product-image-item').length, role:document.querySelector('.cms-product-image-role')?.textContent || ''})")
    expect(mobile_admin == {"products": 4, "panelHidden": False, "width": 390, "images": 1, "role": "Primary"}, "Mobile Admin Shop/gallery regression failed")
    checks += 1
    capture_screenshot(cdp, "shop-admin-b4-mobile.png")

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
    shop_media_root = os.path.abspath(SHOP_MEDIA_DIR)
    for uploaded_path in uploaded_test_paths:
        if not re.fullmatch(r"img/projectfolder/shop/[0-9a-f]{32}\.(?:jpg|png|gif|webp)", uploaded_path):
            continue
        disk_path = os.path.abspath(os.path.join(ROOT, *uploaded_path.split("/")))
        if os.path.commonpath([disk_path, shop_media_root]) == shop_media_root and os.path.isfile(disk_path):
            os.remove(disk_path)
    source_path = os.path.abspath(source_image.name)
    if os.path.commonpath([source_path, os.path.abspath(tempfile.gettempdir())]) == os.path.abspath(tempfile.gettempdir()) and os.path.isfile(source_path):
        os.remove(source_path)

status, final_admin = api_request("admin-products", session_id=None)
expect(status == 401, "Browser test session remained authenticated after logout")

subprocess.run([
    PHP,
    "-r",
    f'session_id("{session_id}"); session_start(); session_destroy();',
], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

print(f"Shop UI browser regression passed: {checks} assertions at desktop and mobile widths.")
