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


def mysql_value(sql):
    result = subprocess.run([
        MYSQL, "--host=127.0.0.1", "--user=root", "--database=dyndel_portfolio",
        "--batch", "--skip-column-names", "--execute=" + sql,
    ], check=True, capture_output=True, text=True)
    return result.stdout.strip()


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
            try:
                if self.evaluate(expression):
                    return
            except RuntimeError as error:
                if "Inspected target navigated or closed" not in str(error):
                    raise
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


def capture_screenshot(cdp, filename, full_page=True):
    if not ARTIFACT_DIR:
        return
    os.makedirs(ARTIFACT_DIR, exist_ok=True)
    result = cdp.call("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": full_page})
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
    "--execute=SELECT TABLE_NAME, COALESCE(AUTO_INCREMENT, 1) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('shop_products','shop_product_images','shop_product_badges','shop_orders','shop_order_items','shop_shipping_zones','shop_shipping_methods') ORDER BY TABLE_NAME;",
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
    status, navigation_content = api_request("content")
    expect(status == 200 and navigation_content.get("entries"), "No published Story was available for navigation regression")
    navigation_story = navigation_content["entries"][0]
    desktop_routes = [
        ("index.html", "home", ""),
        ("illustration.html", "works", "illustration"),
        ("portraits.html", "works", "portraits"),
        ("logos.html", "works", "logos"),
        ("stories.php", "stories", ""),
        ("story.php?slug=" + urllib.parse.quote(navigation_story["slug"]), "stories", ""),
    ]
    for route, expected_section, expected_child in desktop_routes:
        expected_path = urllib.parse.urlparse(BASE + route).path
        cdp.navigate(BASE + route, f"document.readyState !== 'loading' && location.pathname === {js_string(expected_path)} && document.querySelectorAll('.nav-list > li').length === 5")
        navigation_state = cdp.evaluate("""
            (() => {
                const cart=document.querySelector('[data-open-cart]');
                const header=document.querySelector('.header');
                const container=document.querySelector('.nav-container');
                const nav=document.querySelector('.nav');
                const navList=document.querySelector('.nav-list');
                const menuToggle=document.querySelector('.menu-toggle');
                const topItems=[...document.querySelectorAll('.nav-list > li')].map(item => item.querySelector(':scope > a, :scope > button'));
                const itemRects=topItems.map(item => item.getBoundingClientRect());
                const navRect=nav.getBoundingClientRect();
                const containerRect=container.getBoundingClientRect();
                return {
                    labels:topItems.map(item => item.textContent.replace('▾','').trim()),
                    worksChildren:[...document.querySelectorAll('.nav-submenu > li > a')].map(item => item.textContent.trim()),
                    graphicDesignLinks:document.querySelectorAll('.nav a[href*="graphic-design"]').length,
                    currentTop:topItems.filter(item => item.hasAttribute('aria-current')).map(item => item.dataset.navSection || 'works'),
                    currentChild:document.querySelector('[data-works-section][aria-current]')?.dataset.worksSection || '',
                    worksExpanded:document.querySelector('[data-works-toggle]').getAttribute('aria-expanded'),
                    cartVisibility:getComputedStyle(cart).visibility,
                    cartTabIndex:cart.tabIndex,
                    navDisplay:getComputedStyle(nav).display,
                    navPosition:getComputedStyle(nav).position,
                    navDirection:getComputedStyle(navList).flexDirection,
                    menuDisplay:getComputedStyle(menuToggle).display,
                    itemsHorizontal:itemRects.every(rect => Math.abs(rect.top-itemRects[0].top) < 1) && itemRects.slice(1).every((rect,index) => rect.left >= itemRects[index].right),
                    navContained:navRect.left >= containerRect.left && navRect.right <= containerRect.right && header.getBoundingClientRect().width <= innerWidth,
                    positions:topItems.map(item => Math.round(item.getBoundingClientRect().left * 10) / 10),
                    overflow:document.documentElement.scrollWidth > innerWidth
                };
            })()
        """)
        expect(navigation_state["labels"] == ["Home", "Works", "Stories", "Contact", "Store"], f"Public navigation structure was incorrect on {route}")
        expect(navigation_state["worksChildren"] == ["Illustration", "Portraits", "Logos"] and navigation_state["graphicDesignLinks"] == 0, f"Works children were exposed incorrectly on {route}: " + json.dumps(navigation_state))
        expect(navigation_state["currentTop"] == [expected_section] and navigation_state["currentChild"] == expected_child and navigation_state["worksExpanded"] == "false", f"Active navigation state was incorrect on {route}: " + json.dumps(navigation_state))
        expect(navigation_state["navDisplay"] != "none" and navigation_state["navPosition"] == "static" and navigation_state["navDirection"] == "row" and navigation_state["menuDisplay"] == "none" and navigation_state["itemsHorizontal"] and navigation_state["navContained"], f"Desktop navigation layout was not horizontal and contained on {route}: " + json.dumps(navigation_state))
        expect(navigation_state["cartVisibility"] == "hidden" and navigation_state["cartTabIndex"] == -1 and not navigation_state["overflow"], f"Cart or horizontal layout regressed outside Store on {route}")
        if expected_child:
            capture_screenshot(cdp, f"navigation-desktop-{expected_child}.png", full_page=False)
        checks += 5

    cdp.call("Page.bringToFront")
    dropdown_state = cdp.evaluate("""
        (async () => {
            const works=document.querySelector('.nav-works');
            const toggle=document.querySelector('[data-works-toggle]');
            const firstChild=works.querySelector('.nav-submenu a');
            const home=document.querySelector('[data-nav-section="home"]');
            document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
            toggle.focus({preventScroll:true});
            toggle.click();
            const clickClass=works.classList.contains('is-open');
            const clickAria=toggle.getAttribute('aria-expanded');
            const clickVisibility=getComputedStyle(firstChild).visibility;
            const clickSubmenuVisibility=getComputedStyle(works.querySelector('.nav-submenu')).visibility;
            const clickNavVisibility=getComputedStyle(document.querySelector('.nav')).visibility;
            const clickOpen=clickClass && clickAria === 'true' && clickVisibility === 'visible';
            document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
            const escapeClosed=!works.classList.contains('is-open') && toggle.getAttribute('aria-expanded') === 'false' && document.activeElement === toggle;
            toggle.focus({preventScroll:true});
            toggle.dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true}));
            const arrowClass=works.classList.contains('is-open');
            const arrowActive=document.activeElement?.outerHTML || '';
            const arrowFocused=arrowClass && document.activeElement === firstChild;
            home.focus({preventScroll:true});
            const focusOutsideClosed=!works.classList.contains('is-open');
            works.dispatchEvent(new PointerEvent('pointerenter'));
            const hoverOpen=works.classList.contains('is-open') && toggle.getAttribute('aria-expanded') === 'true';
            works.dispatchEvent(new PointerEvent('pointerleave'));
            await new Promise(resolve => setTimeout(resolve,160));
            const hoverClosed=!works.classList.contains('is-open');
            toggle.click();
            document.body.dispatchEvent(new PointerEvent('pointerdown',{bubbles:true}));
            const pointerOutsideClosed=!works.classList.contains('is-open');
            return {clickOpen,clickClass,clickAria,clickVisibility,clickSubmenuVisibility,clickNavVisibility,innerWidth,desktopMedia:matchMedia('(min-width:761px)').matches,escapeClosed,arrowFocused,arrowClass,arrowActive,focusOutsideClosed,hoverOpen,hoverClosed,pointerOutsideClosed,links:[...works.querySelectorAll('a')].map(link=>link.getAttribute('href'))};
        })()
    """, await_promise=True)
    expect(all(dropdown_state[key] for key in ["clickOpen", "escapeClosed", "arrowFocused", "focusOutsideClosed", "hoverOpen", "hoverClosed", "pointerOutsideClosed"]), "Desktop Works pointer/keyboard behavior failed: " + json.dumps(dropdown_state))
    expect(dropdown_state["links"] == ["illustration.html", "portraits.html", "logos.html"], "Works child links stopped being normal destinations")
    cdp.evaluate("document.activeElement?.blur(); document.querySelector('.nav-works').dispatchEvent(new PointerEvent('pointerenter'))")
    capture_screenshot(cdp, "navigation-desktop-works.png", full_page=False)
    cdp.evaluate("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}))")

    header_state = cdp.evaluate("""
        (() => {
            document.activeElement?.blur();
            scrollTo(0,0);
            const header=document.querySelector('.header');
            return {position:getComputedStyle(header).position,normalHeight:header.getBoundingClientRect().height,normalLogo:document.querySelector('.logo-wrap img').getBoundingClientRect().width,enter:Number(header.dataset.compactEnterThreshold),exit:Number(header.dataset.compactExitThreshold)};
        })()
    """)
    expect(header_state["enter"] > header_state["exit"] >= 0 and header_state["enter"] - header_state["exit"] >= 24, "Compact header did not expose a meaningful hysteresis band: " + json.dumps(header_state))
    compact_trace = cdp.evaluate("""
        (async () => {
            const header=document.querySelector('.header');
            const enter=Number(header.dataset.compactEnterThreshold);
            const exit=Number(header.dataset.compactExitThreshold);
            const transitions=[];
            let previous=header.classList.contains('is-compact');
            const observer=new MutationObserver(() => {
                const current=header.classList.contains('is-compact');
                if (current !== previous) {
                    previous=current;
                    transitions.push({compact:current,scrollY:window.scrollY});
                }
            });
            observer.observe(header,{attributes:true,attributeFilter:['class']});
            const settle=()=>new Promise(resolve=>setTimeout(resolve,240));
            const move=async target=>{
                scrollTo({top:target,left:0,behavior:'instant'});
                await settle();
                return {target,scrollY:window.scrollY,compact:header.classList.contains('is-compact'),height:header.getBoundingClientRect().height};
            };
            const samples=[];
            samples.push(await move(0));
            samples.push(await move(enter-2));
            samples.push(await move(enter+2));
            const afterEntry=transitions.length;
            await new Promise(resolve=>setTimeout(resolve,480));
            const afterStationary=transitions.length;
            for (const target of [enter-1,enter+1,enter-2,enter+2,enter]) samples.push(await move(target));
            const afterTinyMoves=transitions.length;
            samples.push(await move(exit+2));
            samples.push(await move(exit-2));
            const afterExit=transitions.length;
            samples.push(await move(460));
            samples.push(await move(0));
            const normalPositions=[...document.querySelectorAll('.nav-list > li')].map(item=>Math.round(item.getBoundingClientRect().left*10)/10);
            samples.push(await move(460));
            const compactPositions=[...document.querySelectorAll('.nav-list > li')].map(item=>Math.round(item.getBoundingClientRect().left*10)/10);
            samples.push(await move(0));
            observer.disconnect();
            return {enter,exit,transitions,afterEntry,afterStationary,afterTinyMoves,afterExit,samples,normalPositions,compactPositions,overflow:document.documentElement.scrollWidth>innerWidth};
        })()
    """, await_promise=True)
    expect(compact_trace["samples"][1]["compact"] is False and compact_trace["samples"][2]["compact"] is True, "Compact header did not enter once after the downward threshold crossing: " + json.dumps(compact_trace))
    expect(compact_trace["afterEntry"] == 1 and compact_trace["afterStationary"] == 1 and compact_trace["afterTinyMoves"] == 1, "Compact header toggled while stationary or during tiny movements inside the hysteresis band: " + json.dumps(compact_trace))
    expect(compact_trace["samples"][8]["compact"] is True and compact_trace["samples"][9]["compact"] is False and compact_trace["afterExit"] == 2, "Compact header did not remain stable until the upward exit threshold crossing: " + json.dumps(compact_trace))
    expect(len(compact_trace["transitions"]) == 6 and [item["compact"] for item in compact_trace["transitions"]] == [True, False, True, False, True, False], "Rapid desktop scrolling caused extra compact-header transitions: " + json.dumps(compact_trace))
    expect(compact_trace["normalPositions"] == compact_trace["compactPositions"] and not compact_trace["overflow"], "Compact-header transition shifted desktop navigation or introduced horizontal overflow: " + json.dumps(compact_trace))
    cdp.evaluate(f"scrollTo({{top:{header_state['enter'] + 2},left:0,behavior:'instant'}})")
    cdp.wait_for("document.querySelector('.header').classList.contains('is-compact')", 5)
    cdp.evaluate("""
        (() => {
            const header=document.querySelector('.header');
            window.__compactWheelTransitions=[];
            window.__compactWheelPrevious=header.classList.contains('is-compact');
            window.__compactWheelObserver=new MutationObserver(()=>{
                const current=header.classList.contains('is-compact');
                if(current!==window.__compactWheelPrevious){
                    window.__compactWheelPrevious=current;
                    window.__compactWheelTransitions.push({compact:current,scrollY:window.scrollY});
                }
            });
            window.__compactWheelObserver.observe(header,{attributes:true,attributeFilter:['class']});
        })()
    """)
    for wheel_delta in (-2, 1, -1, 2, -2, 1):
        cdp.call("Input.dispatchMouseEvent", {"type": "mouseWheel", "x": 720, "y": 320, "deltaX": 0, "deltaY": wheel_delta})
        time.sleep(0.08)
    time.sleep(0.3)
    wheel_trace = cdp.evaluate("""
        (() => {
            window.__compactWheelObserver.disconnect();
            const result={transitions:window.__compactWheelTransitions,compact:document.querySelector('.header').classList.contains('is-compact'),scrollY:window.scrollY};
            delete window.__compactWheelObserver;
            delete window.__compactWheelTransitions;
            delete window.__compactWheelPrevious;
            return result;
        })()
    """)
    expect(wheel_trace["compact"] and wheel_trace["transitions"] == [] and wheel_trace["scrollY"] > header_state["exit"], "Tiny desktop wheel movements caused compact-header jitter: " + json.dumps(wheel_trace))
    cdp.evaluate("scrollTo(0,420)")
    cdp.wait_for(f"document.querySelector('.header').classList.contains('is-compact') && document.querySelector('.header').getBoundingClientRect().height < {header_state['normalHeight'] - 1}")
    compact_state = cdp.evaluate("({height:document.querySelector('.header').getBoundingClientRect().height,logo:document.querySelector('.logo-wrap img').getBoundingClientRect().width})")
    expect(header_state["position"] == "sticky" and compact_state["height"] < header_state["normalHeight"] and compact_state["logo"] < header_state["normalLogo"], "Sticky compact-on-scroll header did not reduce restrainedly: " + json.dumps({"normal": header_state, "compact": compact_state}))
    capture_screenshot(cdp, "navigation-desktop-compact.png", full_page=False)
    cdp.evaluate("scrollTo(0,0)")
    cdp.wait_for(f"!document.querySelector('.header').classList.contains('is-compact') && Math.abs(document.querySelector('.header').getBoundingClientRect().height - {header_state['normalHeight']}) < 1")
    expect(abs(cdp.evaluate("document.querySelector('.header').getBoundingClientRect().height") - header_state["normalHeight"]) < 1, "Header did not return to its normal top-of-page height")
    cdp.call("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-reduced-motion", "value": "reduce"}]})
    expect(cdp.evaluate("getComputedStyle(document.querySelector('.nav-container')).transitionDuration") == "0s", "Reduced motion did not disable navigation transitions")
    cdp.call("Emulation.setEmulatedMedia", {"features": []})

    theme_signatures = cdp.evaluate("""
        (() => {
            const root=document.documentElement;
            const activeElement=document.querySelector('.nav-list > li > [aria-current]');
            const originalTransition=activeElement.style.transition;
            activeElement.style.transition='none';
            const names=['--blue-deep','--panel-strong','--text'];
            const original=Object.fromEntries(names.map(name=>[name,root.style.getPropertyValue(name)]));
            const palettes=[
                ['default','#c86f52','#fff8f3','#3d2925'],
                ['pastel','#7968d8','#fbf8ff','#34304f'],
                ['midnight','#8ca8ff','#171a2b','#f3f5ff']
            ];
            const signatures=palettes.map(([name,accent,surface,text])=>{
                root.style.setProperty('--blue-deep',accent);
                root.style.setProperty('--panel-strong',surface);
                root.style.setProperty('--text',text);
                return {name,submenu:getComputedStyle(document.querySelector('.nav-submenu')).backgroundColor,active:getComputedStyle(activeElement).color,focus:getComputedStyle(document.querySelector('[data-works-toggle]')).outlineColor};
            });
            names.forEach(name=>original[name] ? root.style.setProperty(name,original[name]) : root.style.removeProperty(name));
            activeElement.style.transition=originalTransition;
            return signatures;
        })()
    """)
    expect(len({item["submenu"] for item in theme_signatures}) == 3 and len({item["active"] for item in theme_signatures}) == 3, "Default, Pastel, and Midnight token palettes did not restyle navigation: " + json.dumps(theme_signatures))
    checks += 14

    store_url = BASE + "store.html"
    cdp.evaluate(f"location.assign({js_string(store_url)})")
    try:
        cdp.wait_for("location.pathname.endsWith('/store.html') && location.search === '' && Boolean(document.querySelector('[data-shop-products]'))", 15)
    except AssertionError:
        raise AssertionError("Could not enter Store after navigation regression: " + json.dumps(cdp.evaluate("({href:location.href,ready:document.readyState,title:document.title,hasShop:Boolean(document.querySelector('[data-shop-products]')),body:document.body?.className || ''})")))
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
            const topItems=[...document.querySelectorAll('.nav-list > li')].map(item => item.querySelector(':scope > a, :scope > button'));
            const cart=document.querySelector('[data-open-cart]');
            const navPositions=topItems.map(item => Math.round(item.getBoundingClientRect().left * 10) / 10);
            document.body.classList.remove('is-store-experience');
            const hiddenCartNavPositions=topItems.map(item => Math.round(item.getBoundingClientRect().left * 10) / 10);
            document.body.classList.add('is-store-experience');
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
                cartWidth:cart.getBoundingClientRect().width,
                cartVisibility:getComputedStyle(cart).visibility,
                cartTabIndex:cart.tabIndex,
                emptyCountHidden:document.querySelector('[data-cart-count]').hidden,
                currentTop:topItems.filter(item => item.hasAttribute('aria-current')).map(item => item.dataset.navSection || 'works'),
                navPositions,
                hiddenCartNavPositions,
                overflow:document.documentElement.scrollWidth > innerWidth
            };
        })()
    """)
    expect(storefront_shell["bannerHeight"] <= 300 and storefront_shell["bannerImages"] == 3 and abs(storefront_shell["bannerLeft"]) < 1 and abs(storefront_shell["bannerRight"] - storefront_shell["viewportWidth"]) < 1 and abs(storefront_shell["bannerHeaderGap"]) < 1, "Desktop Shop banner was not full-bleed, flush to the header, short, and artwork-led: " + json.dumps(storefront_shell))
    expect(storefront_shell["headings"] == ["Art Prints"] and storefront_shell["columns"] == 3 and not storefront_shell["overflow"], "Desktop collection/grid layout was incorrect")
    expect(0 <= storefront_shell["titlePriceGap"] <= 8 and storefront_shell["cardRadius"] >= 10 and storefront_shell["cardSurface"] != "rgba(0, 0, 0, 0)" and storefront_shell["linkCoversCard"], "Desktop product cards were not compact, rounded, surfaced, and fully linked")
    expect(storefront_shell["cartInHeader"] and storefront_shell["cartWidth"] == 82 and storefront_shell["cartVisibility"] == "visible" and storefront_shell["cartTabIndex"] == 0 and storefront_shell["emptyCountHidden"] and storefront_shell["currentTop"] == ["store"], "Desktop Store/Cart navigation state was incorrect")
    expect(storefront_shell["navPositions"] == storefront_shell["hiddenCartNavPositions"], "Reserved desktop Cart slot shifted the primary navigation when Cart visibility changed")
    status, theme_response = api_request("theme")
    applied_accent = cdp.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--blue-deep').trim().toLowerCase()")
    expect(status == 200 and applied_accent == theme_response["theme"]["accentColor"].lower(), "Public Shop did not apply the published theme")
    checks += 9

    first_product = initial_public_products[0]
    second_product = initial_public_products[1]
    first_price = float(first_product["currentPrice"])
    second_price = float(second_product["currentPrice"])
    cdp.evaluate("localStorage.removeItem('dyndelShopCart'); location.reload()")
    cdp.wait_for("document.querySelectorAll('.shop-product').length === 3 && document.querySelector('[data-cart-count]').hidden", 15)
    cdp.evaluate("scrollTo({top:120,left:0,behavior:'instant'})")
    cdp.wait_for("document.querySelector('.header').classList.contains('is-compact')", 5)
    cdp.evaluate("new Promise(resolve=>setTimeout(resolve,260))", await_promise=True)
    cart_layout_before = cdp.evaluate("""
        (() => {
            const banner=document.querySelector('.shop-banner').getBoundingClientRect();
            const card=document.querySelector('.shop-product').getBoundingClientRect();
            const topItems=[...document.querySelectorAll('.nav-list > li')].map(item=>item.querySelector(':scope > a, :scope > button'));
            return {banner:{x:banner.x,y:banner.y,width:banner.width,height:banner.height},card:{x:card.x,y:card.y,width:card.width,height:card.height},nav:topItems.map(item=>Math.round(item.getBoundingClientRect().left*10)/10),scrollY,href:location.href};
        })()
    """)
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity) === 1 && document.activeElement === document.querySelector('[data-close-cart]')", 15)
    empty_cart_state = cdp.evaluate("""
        (() => {
            const layer=document.querySelector('[data-cart-region]');
            const drawer=document.querySelector('[data-cart-panel]');
            const drawerRect=drawer.getBoundingClientRect();
            const banner=document.querySelector('.shop-banner').getBoundingClientRect();
            const card=document.querySelector('.shop-product').getBoundingClientRect();
            const topItems=[...document.querySelectorAll('.nav-list > li')].map(item=>item.querySelector(':scope > a, :scope > button'));
            return {
                layerPosition:getComputedStyle(layer).position,drawerPosition:getComputedStyle(drawer).position,
                drawerRight:drawerRect.right,drawerWidth:drawerRect.width,drawerHeight:drawerRect.height,
                backdropOpacity:Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity),
                role:drawer.getAttribute('role'),modal:drawer.getAttribute('aria-modal'),expanded:document.querySelector('[data-open-cart]').getAttribute('aria-expanded'),
                rootOverflow:getComputedStyle(document.documentElement).overflow,bodyOverflow:getComputedStyle(document.body).overflow,scrollY,
                pageInert:document.querySelector('.header').inert && document.querySelector('main').inert,
                empty:document.querySelector('.shop-empty-cart')?.textContent || '',subtotal:document.querySelector('[data-cart-total]').textContent,
                checkoutControls:drawer.querySelectorAll('form, [data-checkout-form], input, textarea, button[type="submit"]').length,
                banner:{x:banner.x,y:banner.y,width:banner.width,height:banner.height},card:{x:card.x,y:card.y,width:card.width,height:card.height},
                nav:topItems.map(item=>Math.round(item.getBoundingClientRect().left*10)/10),overflow:document.documentElement.scrollWidth>innerWidth,
                drawerInBody:drawer.closest('[data-cart-region]')?.parentElement===document.body
            };
        })()
    """)
    expect(empty_cart_state["layerPosition"] == "fixed" and empty_cart_state["drawerPosition"] == "fixed" and empty_cart_state["drawerRight"] == 1440 and 440 <= empty_cart_state["drawerWidth"] <= 520 and empty_cart_state["drawerHeight"] == 1000 and empty_cart_state["drawerInBody"], "Desktop Cart was not a generous, responsive right-edge fixed modal drawer: " + json.dumps(empty_cart_state))
    expect(empty_cart_state["backdropOpacity"] > 0 and empty_cart_state["role"] == "dialog" and empty_cart_state["modal"] == "true" and empty_cart_state["expanded"] == "true" and empty_cart_state["pageInert"], "Cart backdrop or modal semantics were incorrect")
    expect(empty_cart_state["rootOverflow"] == "hidden" and empty_cart_state["bodyOverflow"] == "hidden" and abs(empty_cart_state["scrollY"] - cart_layout_before["scrollY"]) <= 1 and "Your cart is empty." in empty_cart_state["empty"] and empty_cart_state["subtotal"] == "$0.00" and empty_cart_state["checkoutControls"] == 0, "Empty Cart, scroll lock, or checkout removal was incorrect: " + json.dumps({"scroll": cart_layout_before["scrollY"], "open": empty_cart_state}))
    expect(empty_cart_state["banner"] == cart_layout_before["banner"] and empty_cart_state["card"] == cart_layout_before["card"] and empty_cart_state["nav"] == cart_layout_before["nav"] and not empty_cart_state["overflow"], "Opening the empty Cart reflowed the Store or navigation: " + json.dumps({"before": cart_layout_before, "open": empty_cart_state}))

    cdp.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", "key": "Tab", "code": "Tab", "windowsVirtualKeyCode": 9, "modifiers": 8})
    cdp.call("Input.dispatchKeyEvent", {"type": "keyUp", "key": "Tab", "code": "Tab", "windowsVirtualKeyCode": 9, "modifiers": 8})
    expect(cdp.evaluate("document.activeElement === document.querySelector('[data-continue-shopping]')") is True, "Shift+Tab did not wrap focus to the final Cart control")
    cdp.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", "key": "Tab", "code": "Tab", "windowsVirtualKeyCode": 9})
    cdp.call("Input.dispatchKeyEvent", {"type": "keyUp", "key": "Tab", "code": "Tab", "windowsVirtualKeyCode": 9})
    expect(cdp.evaluate("document.activeElement === document.querySelector('[data-close-cart]')") is True, "Tab did not wrap focus to the first Cart control")
    cdp.evaluate("document.querySelector('[data-continue-shopping]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)
    continue_state = cdp.evaluate("({href:location.href,scrollY,focus:document.activeElement===document.querySelector('[data-open-cart]'),bodyPosition:getComputedStyle(document.body).position})")
    expect(continue_state == {"href": cart_layout_before["href"], "scrollY": cart_layout_before["scrollY"], "focus": True, "bodyPosition": "static"}, "Continue Shopping navigated, lost position, or failed to restore focus: " + json.dumps(continue_state))

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 1200, "height": 800, "deviceScaleFactor": 1, "mobile": False,
    })
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity) === 1", 5)
    responsive_desktop_cart = cdp.evaluate("(() => { const rect=document.querySelector('[data-cart-panel]').getBoundingClientRect(); return {width:rect.width,right:rect.right,overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    expect(455 <= responsive_desktop_cart["width"] <= 457 and responsive_desktop_cart["right"] == 1200 and not responsive_desktop_cart["overflow"], "Cart width did not scale proportionally on a smaller desktop viewport: " + json.dumps(responsive_desktop_cart))
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)
    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
    })

    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open')", 5)
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    closing_motion = cdp.evaluate("new Promise(resolve=>setTimeout(()=>{const drawer=document.querySelector('[data-cart-panel]');resolve({hidden:document.querySelector('[data-cart-region]').hidden,transform:getComputedStyle(drawer).transform});},90))", await_promise=True)
    expect(not closing_motion["hidden"] and closing_motion["transform"] != "none" and closing_motion["transform"] != "matrix(1, 0, 0, 1, 0, 0)", "Cart did not slide toward the right while closing")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open')", 5)
    cdp.evaluate("document.querySelector('[data-cart-backdrop]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open')", 5)
    cdp.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", "key": "Escape", "code": "Escape", "windowsVirtualKeyCode": 27})
    cdp.call("Input.dispatchKeyEvent", {"type": "keyUp", "key": "Escape", "code": "Escape", "windowsVirtualKeyCode": 27})
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden && document.activeElement === document.querySelector('[data-open-cart]')", 5)
    checks += 11

    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', JSON.stringify([{{id:{first_product['id']},quantity:1}},{{id:{second_product['id']},quantity:2}}])); location.reload()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '3'", 15)
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && document.querySelectorAll('.shop-cart-item').length === 2", 15)
    multi_cart = cdp.evaluate("(() => { const row=document.querySelector('.shop-cart-item'); const info=row.querySelector('.shop-cart-item-copy').getBoundingClientRect(); const actions=row.querySelector('.shop-cart-item-actions').getBoundingClientRect(); const quantity=row.querySelector('.shop-cart-quantity').getBoundingClientRect(); const remove=row.querySelector('.shop-cart-remove').getBoundingClientRect(); return {rows:document.querySelectorAll('.shop-cart-item').length,count:document.querySelector('[data-cart-count]').textContent,subtotal:document.querySelector('[data-cart-total]').textContent,quantities:[...document.querySelectorAll('.shop-cart-quantity-value')].map(item=>item.textContent),touch:[...document.querySelectorAll('.shop-cart-quantity button')].every(button=>button.getBoundingClientRect().width>=44&&button.getBoundingClientRect().height>=44),alt:[...document.querySelectorAll('.shop-cart-item img')].every(image=>Boolean(image.alt)),overflowY:getComputedStyle(document.querySelector('[data-cart-items]')).overflowY,actionColumn:actions.left>=info.right&&remove.top>=quantity.bottom}; })()")
    expect(multi_cart["rows"] == 2 and multi_cart["count"] == "3" and multi_cart["subtotal"] == f"${first_price + second_price * 2:.2f}" and multi_cart["quantities"] == ["1", "2"], "Multiple-item quantity, count, or subtotal rendering failed: " + json.dumps(multi_cart))
    expect(multi_cart["touch"] and multi_cart["alt"] and multi_cart["overflowY"] == "auto", "Cart thumbnails, quantity targets, or scroll area were inaccessible")
    expect(multi_cart["actionColumn"], "Desktop quantity and Remove controls were not grouped in the right-side action column")
    cdp.evaluate(f"document.querySelector('[data-cart-increase=\"{first_product['id']}\"]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '4'", 5)
    expect(cdp.evaluate(f"document.querySelector('[data-cart-total]').textContent === '${first_price * 2 + second_price * 2:.2f}' && JSON.parse(localStorage.getItem('dyndelShopCart')).find(item=>item.id==={first_product['id']}).quantity===2") is True, "Quantity increase did not immediately update storage, count, and subtotal")
    cdp.evaluate(f"document.querySelector('[data-cart-decrease=\"{first_product['id']}\"]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '3'", 5)
    cdp.wait_for(f"document.activeElement === document.querySelector('[data-cart-increase=\"{first_product['id']}\"]')", 5)
    capture_screenshot(cdp, "shop-e-store-cart-desktop.png", full_page=False)

    cart_theme_signatures = cdp.evaluate("""
        (() => {
            const root=document.documentElement;
            const names=['--blue-deep','--panel-strong','--text'];
            const original=Object.fromEntries(names.map(name=>[name,root.style.getPropertyValue(name)]));
            const palettes=[['default','#c86f52','#fff8f3','#3d2925'],['pastel','#7968d8','#fbf8ff','#34304f'],['midnight','#8ca8ff','#171a2b','#f3f5ff']];
            const values=palettes.map(([name,accent,surface,text])=>{root.style.setProperty('--blue-deep',accent);root.style.setProperty('--panel-strong',surface);root.style.setProperty('--text',text);return{name,drawer:getComputedStyle(document.querySelector('[data-cart-panel]')).backgroundColor,quantity:getComputedStyle(document.querySelector('.shop-cart-quantity')).backgroundColor,focus:getComputedStyle(document.querySelector('[data-close-cart]')).outlineColor};});
            names.forEach(name=>original[name]?root.style.setProperty(name,original[name]):root.style.removeProperty(name));
            return values;
        })()
    """)
    expect(len({item["drawer"] for item in cart_theme_signatures}) == 3 and len({item["quantity"] for item in cart_theme_signatures}) == 3, "Default, Pastel, and Midnight tokens did not restyle Cart V2: " + json.dumps(cart_theme_signatures))
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)

    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', JSON.stringify([{{id:{first_product['id']},quantity:{first_product['stock']}}}])); location.reload()")
    cdp.wait_for(f"document.querySelector('[data-cart-count]').textContent === '{first_product['stock']}'", 15)
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open')", 5)
    cdp.evaluate(f"document.querySelector('[data-cart-increase=\"{first_product['id']}\"]').click()")
    stock_limit_state = cdp.evaluate(f"({{quantity:document.querySelector('.shop-cart-quantity-value').textContent,count:document.querySelector('[data-cart-count]').textContent,stored:JSON.parse(localStorage.getItem('dyndelShopCart'))[0].quantity,feedback:document.querySelector('[data-cart-feedback]').textContent,nav:[...document.querySelectorAll('.nav-list>li')].map(item=>Math.round(item.getBoundingClientRect().left*10)/10)}})")
    expect(stock_limit_state["quantity"] == str(first_product["stock"]) and stock_limit_state["count"] == str(first_product["stock"]) and stock_limit_state["stored"] == first_product["stock"] and "available limit" in stock_limit_state["feedback"], "Stock ceiling was not enforced with restrained feedback")
    expect(stock_limit_state["nav"] == storefront_shell["navPositions"], "A two-digit Cart count or open drawer shifted desktop navigation")
    cdp.evaluate("document.querySelector('[data-remove-cart]').click()")
    cdp.wait_for("document.querySelector('.shop-empty-cart') && document.querySelector('[data-cart-count]').hidden && !document.querySelector('[data-cart-region]').hidden && document.querySelector('[data-cart-total]').textContent === '$0.00' && JSON.parse(localStorage.getItem('dyndelShopCart')).length === 0 && document.activeElement === document.querySelector('[data-continue-shopping]')", 5)
    cdp.call("Emulation.setEmulatedMedia", {"features": [{"name": "prefers-reduced-motion", "value": "reduce"}]})
    expect(cdp.evaluate("getComputedStyle(document.querySelector('[data-cart-panel]')).transitionDuration === '0s' && getComputedStyle(document.querySelector('[data-cart-backdrop]')).transitionDuration === '0s'") is True, "Reduced motion did not disable Cart drawer/backdrop transitions")
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 2)
    cdp.call("Emulation.setEmulatedMedia", {"features": []})

    cdp.evaluate("localStorage.setItem('dyndelShopCart', JSON.stringify({bad:true})); location.reload()")
    cdp.wait_for("document.querySelectorAll('.shop-product').length === 3", 15)
    expect(cdp.evaluate("JSON.stringify(JSON.parse(localStorage.getItem('dyndelShopCart'))) === '[]' && document.querySelector('[data-cart-count]').hidden") is True, "Malformed non-array Cart storage was not normalized safely")
    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', JSON.stringify([null,{{id:'bad',quantity:2}},{{id:{first_product['id']},quantity:1}},{{id:{first_product['id']},quantity:2}},{{id:999999,quantity:1}}])); location.reload()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '3'", 15)
    resilient_cart = cdp.evaluate("JSON.parse(localStorage.getItem('dyndelShopCart'))")
    expect(resilient_cart == [{"id": first_product["id"], "quantity": 3}], "Malformed, duplicate, or stale Cart entries were not reconciled safely: " + json.dumps(resilient_cart))
    cdp.evaluate("localStorage.removeItem('dyndelShopCart'); location.reload()")
    cdp.wait_for("document.querySelectorAll('.shop-product').length === 3 && document.querySelector('[data-cart-count]').hidden", 15)
    checks += 13

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    mobile_home_url = BASE + "index.html?nav-mobile=" + token
    cdp.navigate(mobile_home_url, f"document.readyState !== 'loading' && location.search === '?nav-mobile={token}' && Boolean(document.querySelector('[data-nav-section=\"home\"][aria-current]'))")
    mobile_home = cdp.evaluate("""
        (() => {
            const cart=document.querySelector('[data-open-cart]');
            const slot=document.querySelector('.header-cart-slot');
            return {cartDisplay:getComputedStyle(slot).display,cartVisibility:getComputedStyle(cart).visibility,cartTabIndex:cart.tabIndex,cartHidden:cart.getAttribute('aria-hidden'),overflow:document.documentElement.scrollWidth > innerWidth};
        })()
    """)
    expect(mobile_home == {"cartDisplay": "none", "cartVisibility": "hidden", "cartTabIndex": -1, "cartHidden": "true", "overflow": False}, "Mobile Cart occupied visible or keyboard space outside Store")
    cdp.evaluate("document.querySelector('.menu-toggle').click(); document.querySelector('[data-works-toggle]').click()")
    cdp.wait_for("getComputedStyle(document.querySelector('.nav')).visibility === 'visible' && Number(getComputedStyle(document.querySelector('.nav')).opacity) === 1 && getComputedStyle(document.querySelector('.nav-submenu')).display === 'grid'", 5)
    mobile_works = cdp.evaluate("""
        (() => {
            const toggle=document.querySelector('[data-works-toggle]');
            const nav=document.querySelector('.nav');
            const submenu=document.querySelector('.nav-submenu');
            const child=submenu.querySelector('a');
            const store=document.querySelector('[data-nav-section="store"]');
            const storeRect=store.getBoundingClientRect();
            const navRect=nav.getBoundingClientRect();
            return {navOpen:nav.classList.contains('open'),navVisibility:getComputedStyle(nav).visibility,navOpacity:Number(getComputedStyle(nav).opacity),expanded:toggle.getAttribute('aria-expanded'),submenuDisplay:getComputedStyle(submenu).display,submenuPosition:getComputedStyle(submenu).position,touchHeight:child.getBoundingClientRect().height,storeDisplay:getComputedStyle(store).display,storeText:store.textContent.trim(),storeInside:storeRect.top >= navRect.top && storeRect.bottom <= navRect.bottom,storeInViewport:storeRect.bottom <= innerHeight,overflow:submenu.scrollWidth > submenu.clientWidth};
        })()
    """)
    expect(mobile_works["navOpen"] and mobile_works["navVisibility"] == "visible" and mobile_works["navOpacity"] == 1 and mobile_works["expanded"] == "true" and mobile_works["submenuDisplay"] == "grid" and mobile_works["submenuPosition"] == "static" and mobile_works["touchHeight"] >= 40 and mobile_works["storeDisplay"] == "flex" and mobile_works["storeText"] == "Store" and mobile_works["storeInside"] and mobile_works["storeInViewport"] and not mobile_works["overflow"], "Mobile Works did not expand accessibly with all destinations in the burger panel: " + json.dumps(mobile_works))
    capture_screenshot(cdp, "navigation-mobile-works.png", full_page=False)
    cdp.evaluate("""
        (() => {
            const child=document.querySelector('[data-works-section="illustration"]');
            child.addEventListener('click',event=>event.preventDefault(),{once:true});
            child.click();
        })()
    """)
    expect(cdp.evaluate("!document.querySelector('.nav').classList.contains('open') && document.querySelector('.menu-toggle').getAttribute('aria-expanded') === 'false' && document.querySelector('[data-works-toggle]').getAttribute('aria-expanded') === 'false'") is True, "Selecting a mobile Works child did not close the Works and burger menus")
    checks += 3

    mobile_work_routes = [
        ("illustration.html", "illustration"),
        ("portraits.html", "portraits"),
        ("logos.html", "logos"),
    ]
    for route, expected_child in mobile_work_routes:
        cdp.navigate(BASE + route)
        cdp.wait_for(f"Boolean(document.querySelector('[data-works-toggle][aria-current=\"page\"]') && document.querySelector('[data-works-section=\"{expected_child}\"][aria-current=\"page\"]'))", 15)
        mobile_work_closed = cdp.evaluate("""
            (() => {
                const header=document.querySelector('.header').getBoundingClientRect();
                const nav=document.querySelector('.nav');
                const navList=document.querySelector('.nav-list');
                const menu=document.querySelector('.menu-toggle');
                const menuRect=menu.getBoundingClientRect();
                const cart=document.querySelector('[data-open-cart]');
                const topItems=[...document.querySelectorAll('.nav-list > li')].map(item => item.querySelector(':scope > a, :scope > button'));
                return {
                    labels:topItems.map(item => item.dataset.navSection || 'works'),
                    worksChildren:[...document.querySelectorAll('.nav-submenu > li > a')].map(item => item.dataset.worksSection),
                    graphicDesignLinks:document.querySelectorAll('.nav a[href*="graphic-design"]').length,
                    directWorksChildren:document.querySelectorAll('.nav-list > li > [data-works-section]').length,
                    currentTop:topItems.filter(item => item.hasAttribute('aria-current')).map(item => item.dataset.navSection || 'works'),
                    currentChild:document.querySelector('[data-works-section][aria-current]')?.dataset.worksSection || '',
                    menuDisplay:getComputedStyle(menu).display,
                    menuInsideHeader:menuRect.left >= header.left && menuRect.right <= header.right,
                    navPosition:getComputedStyle(nav).position,
                    navVisibility:getComputedStyle(nav).visibility,
                    navDirection:getComputedStyle(navList).flexDirection,
                    cartDisplay:getComputedStyle(document.querySelector('.header-cart-slot')).display,
                    cartVisibility:getComputedStyle(cart).visibility,
                    cartTabIndex:cart.tabIndex,
                    overflow:document.documentElement.scrollWidth > innerWidth
                };
            })()
        """)
        expect(mobile_work_closed["labels"] == ["home", "works", "stories", "contact", "store"] and mobile_work_closed["worksChildren"] == ["illustration", "portraits", "logos"] and mobile_work_closed["graphicDesignLinks"] == 0 and mobile_work_closed["directWorksChildren"] == 0, f"Mobile navigation hierarchy was incorrect on {route}: " + json.dumps(mobile_work_closed))
        expect(mobile_work_closed["currentTop"] == ["works"] and mobile_work_closed["currentChild"] == expected_child and mobile_work_closed["menuDisplay"] == "flex" and mobile_work_closed["menuInsideHeader"] and mobile_work_closed["navPosition"] == "absolute" and mobile_work_closed["navVisibility"] == "hidden" and mobile_work_closed["navDirection"] == "column" and mobile_work_closed["cartDisplay"] == "none" and mobile_work_closed["cartVisibility"] == "hidden" and mobile_work_closed["cartTabIndex"] == -1 and not mobile_work_closed["overflow"], f"Closed mobile navigation layout regressed on {route}: " + json.dumps(mobile_work_closed))

        cdp.evaluate("document.querySelector('.menu-toggle').click(); document.querySelector('[data-works-toggle]').click()")
        cdp.wait_for("getComputedStyle(document.querySelector('.nav')).visibility === 'visible' && Number(getComputedStyle(document.querySelector('.nav')).opacity) === 1 && getComputedStyle(document.querySelector('.nav-submenu')).display === 'grid'", 5)
        mobile_work_open = cdp.evaluate("""
            (() => {
                const nav=document.querySelector('.nav');
                const submenu=document.querySelector('.nav-submenu');
                const navRect=nav.getBoundingClientRect();
                const submenuRect=submenu.getBoundingClientRect();
                const children=[...submenu.querySelectorAll('a')];
                const store=document.querySelector('[data-nav-section="store"]').getBoundingClientRect();
                return {
                    navOpen:nav.classList.contains('open'),
                    navVisible:getComputedStyle(nav).visibility === 'visible' && Number(getComputedStyle(nav).opacity) === 1,
                    worksExpanded:document.querySelector('[data-works-toggle]').getAttribute('aria-expanded'),
                    submenuDisplay:getComputedStyle(submenu).display,
                    submenuPosition:getComputedStyle(submenu).position,
                    childLabels:children.map(child => child.textContent.trim()),
                    childrenInside:children.every(child => { const rect=child.getBoundingClientRect(); return rect.left >= submenuRect.left && rect.right <= navRect.right && rect.top >= navRect.top && rect.bottom <= navRect.bottom; }),
                    storeInside:store.left >= navRect.left && store.right <= navRect.right && store.top >= navRect.top && store.bottom <= navRect.bottom,
                    navInsideViewport:navRect.left >= 0 && navRect.right <= innerWidth,
                    overflow:document.documentElement.scrollWidth > innerWidth || submenu.scrollWidth > submenu.clientWidth
                };
            })()
        """)
        expect(mobile_work_open["navOpen"] and mobile_work_open["navVisible"] and mobile_work_open["worksExpanded"] == "true" and mobile_work_open["submenuDisplay"] == "grid" and mobile_work_open["submenuPosition"] == "static" and mobile_work_open["childLabels"] == ["Illustration", "Portraits", "Logos"] and mobile_work_open["childrenInside"] and mobile_work_open["storeInside"] and mobile_work_open["navInsideViewport"] and not mobile_work_open["overflow"], f"Expanded mobile Works layout regressed on {route}: " + json.dumps(mobile_work_open))
        capture_screenshot(cdp, f"navigation-mobile-{expected_child}.png", full_page=False)
        cdp.evaluate("document.querySelector('.menu-toggle').click()")
        checks += 3

    cdp.navigate(BASE + "store.html", "document.readyState === 'complete' && document.querySelectorAll('.shop-product').length === 3")
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
                cartVisibility:getComputedStyle(cart).visibility,
                cartTabIndex:cart.tabIndex,
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
    expect(mobile_state["cards"] == 3 and mobile_state["cartDisplay"] != "none" and mobile_state["cartVisibility"] == "visible" and mobile_state["cartTabIndex"] == 0 and mobile_state["cartInHeader"] and mobile_state["cartWidth"] < 100 and not mobile_state["cartOverlap"] and mobile_state["navToggle"] != "none", "Mobile Shop catalog, compact cart access, or navigation did not render without overlap")
    expect(mobile_state["columns"] == 2 and mobile_state["bannerHeight"] <= 220 and mobile_state["bannerEdges"] and abs(mobile_state["bannerHeaderGap"]) < 1 and mobile_state["addButtons"] == 0 and not mobile_state["overflow"], "Mobile full-bleed banner/grid layout regressed")
    expect(0 <= mobile_state["titlePriceGap"] <= 8 and mobile_state["cardRadius"] >= 10, "Mobile card rounding or title/price spacing regressed")
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    expect(cdp.evaluate("document.querySelector('.nav').classList.contains('open')") is True, "Mobile navigation toggle did not open")
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    checks += 4

    mobile_cart_seed = [{"id": product["id"], "quantity": 1} for product in initial_public_products]
    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', {js_string(json.dumps(mobile_cart_seed))}); location.reload()")
    cdp.wait_for(f"document.querySelector('[data-cart-count]').textContent === '{len(mobile_cart_seed)}' && document.querySelectorAll('.shop-product').length === 3", 15)
    mobile_store_before_cart = cdp.evaluate("(() => { const banner=document.querySelector('.shop-banner').getBoundingClientRect(); const card=document.querySelector('.shop-product').getBoundingClientRect(); const header=document.querySelector('.header').getBoundingClientRect(); return {banner:{x:banner.x,y:banner.y,width:banner.width},card:{x:card.x,y:card.y,width:card.width},header:{x:header.x,y:header.y,width:header.width},overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity) === 1 && document.activeElement === document.querySelector('[data-close-cart]')", 15)
    mobile_store_cart = cdp.evaluate("""
        (() => {
            const drawer=document.querySelector('[data-cart-panel]');
            const drawerRect=drawer.getBoundingClientRect();
            const banner=document.querySelector('.shop-banner').getBoundingClientRect();
            const card=document.querySelector('.shop-product').getBoundingClientRect();
            const header=document.querySelector('.header').getBoundingClientRect();
            const cartHeader=document.querySelector('.shop-cart-header').getBoundingClientRect();
            const footer=document.querySelector('.shop-cart-footer').getBoundingClientRect();
            const firstRow=drawer.querySelector('.shop-cart-item');
            const information=firstRow.querySelector('.shop-cart-item-copy').getBoundingClientRect();
            const actions=firstRow.querySelector('.shop-cart-item-actions').getBoundingClientRect();
            const quantity=firstRow.querySelector('.shop-cart-quantity').getBoundingClientRect();
            const remove=firstRow.querySelector('.shop-cart-remove').getBoundingClientRect();
            return {
                position:getComputedStyle(drawer).position,width:drawerRect.width,right:drawerRect.right,height:drawerRect.height,
                rows:drawer.querySelectorAll('.shop-cart-item').length,count:document.querySelector('[data-cart-count]').textContent,
                closeVisible:document.querySelector('[data-close-cart]').getBoundingClientRect().bottom <= innerHeight,
                cartHeaderTop:cartHeader.top,footerBottom:footer.bottom,touch:[...drawer.querySelectorAll('.shop-cart-quantity button')].every(button=>button.getBoundingClientRect().width>=44&&button.getBoundingClientRect().height>=44),
                mobileActions:actions.top>=information.bottom&&Math.abs(actions.left-information.left)<1&&remove.top>=quantity.bottom,
                banner:{x:banner.x,y:banner.y,width:banner.width},card:{x:card.x,y:card.y,width:card.width},header:{x:header.x,y:header.y,width:header.width},
                pageLocked:getComputedStyle(document.documentElement).overflow==='hidden'&&getComputedStyle(document.body).overflow==='hidden',burgerInert:document.querySelector('.menu-toggle').closest('.header').inert,
                overflow:document.documentElement.scrollWidth>innerWidth
            };
        })()
    """)
    expect(mobile_store_cart["position"] == "fixed" and 360 <= mobile_store_cart["width"] <= 390 and mobile_store_cart["right"] == 390 and mobile_store_cart["height"] == 844, "Mobile Cart was not a nearly full-width fixed drawer: " + json.dumps(mobile_store_cart))
    expect(mobile_store_cart["rows"] == 3 and mobile_store_cart["count"] == "3" and mobile_store_cart["closeVisible"] and mobile_store_cart["cartHeaderTop"] == 0 and mobile_store_cart["footerBottom"] == 844 and mobile_store_cart["touch"] and mobile_store_cart["mobileActions"], "Mobile Cart content, grouped actions, stable regions, or touch targets were incorrect: " + json.dumps(mobile_store_cart))
    expect(mobile_store_cart["banner"] == mobile_store_before_cart["banner"] and mobile_store_cart["card"] == mobile_store_before_cart["card"] and mobile_store_cart["header"] == mobile_store_before_cart["header"] and mobile_store_cart["pageLocked"] and mobile_store_cart["burgerInert"] and not mobile_store_cart["overflow"], "Opening mobile Cart reflowed the Store or left the page interactive: " + json.dumps({"before": mobile_store_before_cart, "open": mobile_store_cart}))
    capture_screenshot(cdp, "shop-e-store-cart-mobile.png", full_page=False)
    cdp.evaluate("document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 420, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open')", 5)
    short_mobile_cart = cdp.evaluate("(() => { const items=document.querySelector('[data-cart-items]'); const header=document.querySelector('.shop-cart-header').getBoundingClientRect(); const footer=document.querySelector('.shop-cart-footer').getBoundingClientRect(); items.scrollTop=items.scrollHeight; return {scrollable:items.scrollHeight>items.clientHeight,scrolled:items.scrollTop>0,headerTop:header.top,footerBottom:footer.bottom,overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    expect(short_mobile_cart == {"scrollable": True, "scrolled": True, "headerTop": 0, "footerBottom": 420, "overflow": False}, "Short mobile Cart did not keep its header/footer stable with an internally scrolling item list: " + json.dumps(short_mobile_cart))
    cdp.evaluate("document.querySelector('[data-cart-backdrop]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden", 5)
    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })
    cdp.evaluate("localStorage.removeItem('dyndelShopCart'); location.reload()")
    cdp.wait_for("document.querySelectorAll('.shop-product').length === 3 && document.querySelector('[data-cart-count]').hidden", 15)
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

    cdp.navigate(BASE + "store.html")
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
    expect(storefront_state["inquiryHref"] == "index.html#contact" and storefront_state["externalHref"] == storefront_fields["externalUrl"] and storefront_state["internalHook"].startswith("store.html?product="), "Product action links were unsafe or incorrect")
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

    sold_internal_fields = {
        "sku": "TEST-UI-SOLD-" + token.upper(),
        "slug": "test-ui-sold-" + token,
        "title": "Sold Out Internal Detail Fixture",
        "shortDescription": "A disposable sold-out detail fixture.",
        "description": "A disposable internal product used to verify the sold-out Product Detail state.",
        "category": "Regression",
        "productType": "physical",
        "price": "12.00",
        "salePrice": "",
        "stock": "0",
        "publicationStatus": "published",
        "storefrontVisible": "1",
        "showWhenSoldOut": "1",
        "featured": "0",
        "sortOrder": "1000",
        "purchaseAction": "internal",
        "externalUrl": "",
        "images": json.dumps([{"path": primary_url, "altText": "Sold-out fixture artwork", "sortOrder": 1}]),
        "manualBadges": json.dumps([{"label": "Archive", "sortOrder": 1}]),
    }
    status, sold_internal_created = api_request("product", "POST", sold_internal_fields, session_id)
    expect(status == 201, "Could not create disposable sold-out Product Detail fixture")
    sold_internal_id = sold_internal_created["id"]
    fixture_ids.append(sold_internal_id)

    normal_product = initial_public_products[0]
    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(normal_product["slug"]), "Boolean(document.querySelector('.shop-product-detail'))")
    normal_detail = cdp.evaluate("""
        (() => {
            const detail=document.querySelector('.shop-product-detail');
            const image=detail.querySelector('.shop-product-main-image');
            const action=detail.querySelector('[data-add-cart]');
            const store=document.querySelector('[data-nav-section="store"]');
            return {
                productId:Number(detail.dataset.productId),
                title:detail.querySelector('h1').textContent.trim(),
                pageTitle:document.title,
                description:detail.querySelector('.shop-product-description').textContent.trim(),
                price:detail.querySelector('.shop-product-detail-price').textContent.trim(),
                imageLoaded:image.complete && image.naturalWidth > 0,
                imageAlt:image.alt,
                columns:getComputedStyle(detail).gridTemplateColumns.split(' ').length,
                bannerDisplay:getComputedStyle(document.querySelector('.shop-banner')).display,
                catalogDisplay:getComputedStyle(document.querySelector('[data-shop-catalog-view].shop-layout')).display,
                cartVisible:getComputedStyle(document.querySelector('[data-open-cart]')).visibility,
                storeCurrent:store.getAttribute('aria-current'),
                actionText:action?.textContent.trim() || '',
                actionHeight:action?.getBoundingClientRect().height || 0,
                h1Count:document.querySelectorAll('main h1').length,
                rawSkuVisible:document.body.innerText.includes(String(%d)),
                overflow:document.documentElement.scrollWidth > innerWidth
            };
        })()
    """ % normal_product["id"])
    expect(normal_detail["productId"] == normal_product["id"] and normal_detail["title"] == normal_product["title"] and normal_detail["pageTitle"] == normal_product["title"] + " | Dyndel Pino", "Normal internal Product Detail identity or document title was incorrect")
    expect(normal_detail["description"] == normal_product["description"] and normal_detail["price"] and normal_detail["imageLoaded"] and normal_detail["imageAlt"], "Normal Product Detail omitted its description, price, or accessible artwork")
    expect(normal_detail["columns"] == 2 and normal_detail["bannerDisplay"] == "none" and normal_detail["catalogDisplay"] == "none" and not normal_detail["overflow"], "Desktop Product Detail layout, banner removal, or overflow was incorrect: " + json.dumps(normal_detail))
    expect(normal_detail["cartVisible"] == "visible" and normal_detail["storeCurrent"] == "page" and normal_detail["actionText"] == "Add to Cart" and normal_detail["actionHeight"] >= 44 and normal_detail["h1Count"] == 1, "Product Detail navigation, heading, or internal action regressed")

    cdp.evaluate("localStorage.removeItem('dyndelShopCart'); location.reload()")
    cdp.wait_for("Boolean(document.querySelector('.shop-product-detail [data-add-cart]'))", 15)
    cdp.evaluate("document.querySelector('[data-add-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').textContent === '1' && !document.querySelector('.shop-product-confirmation').hidden", 15)
    add_state = cdp.evaluate("({cart:JSON.parse(localStorage.getItem('dyndelShopCart')),message:document.querySelector('.shop-product-confirmation > p').textContent,continueHref:document.querySelector('.shop-product-confirmation a').getAttribute('href')})")
    expect(add_state["cart"] == [{"id": normal_product["id"], "quantity": 1}] and add_state["message"] == "Added to Cart." and add_state["continueHref"] == "store.html", "Product Detail Add to Cart or lightweight confirmation failed")
    detail_layout_before_cart = cdp.evaluate("(() => { const detail=document.querySelector('.shop-product-detail').getBoundingClientRect(); const gallery=document.querySelector('.shop-product-gallery').getBoundingClientRect(); const nav=[...document.querySelectorAll('.nav-list>li')].map(item=>Math.round(item.getBoundingClientRect().left*10)/10); return {detail:{x:detail.x,y:detail.y,width:detail.width},gallery:{x:gallery.x,y:gallery.y,width:gallery.width},nav,overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    cdp.evaluate("[...document.querySelectorAll('.shop-product-confirmation button')].find(button=>button.textContent.includes('View Cart')).click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity) === 1 && Boolean(document.querySelector('.shop-cart-item')) && document.activeElement === document.querySelector('[data-close-cart]')", 15)
    detail_cart = cdp.evaluate("(() => { const detail=document.querySelector('.shop-product-detail').getBoundingClientRect(); const gallery=document.querySelector('.shop-product-gallery').getBoundingClientRect(); return {expanded:document.querySelector('[data-open-cart]').getAttribute('aria-expanded'),item:document.querySelector('.shop-cart-item strong').textContent,total:document.querySelector('[data-cart-total]').textContent,detail:{x:detail.x,y:detail.y,width:detail.width},gallery:{x:gallery.x,y:gallery.y,width:gallery.width},nav:[...document.querySelectorAll('.nav-list>li')].map(item=>Math.round(item.getBoundingClientRect().left*10)/10),overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    expect(detail_cart["expanded"] == "true" and detail_cart["item"] == normal_product["title"] and detail_cart["total"] == "$" + normal_product["currentPrice"], "Product Detail View Cart did not invoke the modal drawer")
    expect(detail_cart["detail"] == detail_layout_before_cart["detail"] and detail_cart["gallery"] == detail_layout_before_cart["gallery"] and detail_cart["nav"] == detail_layout_before_cart["nav"] and not detail_cart["overflow"], "Opening Cart reflowed the desktop Product Detail/gallery: " + json.dumps({"before": detail_layout_before_cart, "open": detail_cart}))
    capture_screenshot(cdp, "shop-e-product-detail-cart-desktop.png", full_page=False)
    cdp.evaluate("document.querySelector('[data-remove-cart]').click(); document.querySelector('[data-close-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-count]').hidden && document.querySelector('[data-cart-region]').hidden", 15)
    checks += 10

    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(v2_fields["slug"]), "document.querySelectorAll('.shop-product-thumbnail').length === 2")
    inquiry_detail = cdp.evaluate("""
        (() => {
            const detail=document.querySelector('.shop-product-detail');
            return {
                badges:[...detail.querySelectorAll('.shop-product-detail-badges .shop-product-badge')].map(item=>item.textContent),
                price:detail.querySelector('.shop-product-detail-price').textContent.replace(/\s+/g,' ').trim(),
                inquiryHref:detail.querySelector('.shop-product-action').getAttribute('href'),
                addButtons:detail.querySelectorAll('[data-add-cart]').length,
                thumbnails:detail.querySelectorAll('.shop-product-thumbnail').length,
                firstPressed:detail.querySelector('.shop-product-thumbnail').getAttribute('aria-pressed'),
                firstAlt:detail.querySelector('.shop-product-main-image').alt,
                featuredVisible:detail.innerText.includes('Featured'),
                rawFieldsVisible:['TEST-UI-V2-','digital'].some(value=>detail.innerText.includes(value))
            };
        })()
    """)
    expect(inquiry_detail["badges"] == ["Sale", "New"] and "$18.00" in inquiry_detail["price"] and "$22.00" in inquiry_detail["price"] and not inquiry_detail["featuredVisible"], "Sale/manual badges or sale pricing were incorrect on Product Detail")
    expect(inquiry_detail["inquiryHref"] == "index.html#contact" and inquiry_detail["addButtons"] == 0 and not inquiry_detail["rawFieldsVisible"], "Inquiry detail exposed a local-cart action or raw implementation fields")
    expect(inquiry_detail["thumbnails"] == 2 and inquiry_detail["firstPressed"] == "true" and inquiry_detail["firstAlt"] == "Uploaded primary alt text", "Ordered gallery, active thumbnail, or stored alt text was incorrect")
    cdp.evaluate("document.querySelectorAll('.shop-product-thumbnail')[1].focus()")
    cdp.call("Input.dispatchKeyEvent", {"type": "keyDown", "key": "Enter", "code": "Enter", "windowsVirtualKeyCode": 13})
    cdp.call("Input.dispatchKeyEvent", {"type": "keyUp", "key": "Enter", "code": "Enter", "windowsVirtualKeyCode": 13})
    cdp.wait_for(f"document.querySelector('.shop-product-main-image').src === {js_string(hover_url)}", 10)
    gallery_state = cdp.evaluate("({src:document.querySelector('.shop-product-main-image').src,alt:document.querySelector('.shop-product-main-image').alt,pressed:[...document.querySelectorAll('.shop-product-thumbnail')].map(item=>item.getAttribute('aria-pressed')),focused:document.activeElement===document.querySelectorAll('.shop-product-thumbnail')[1],outline:getComputedStyle(document.activeElement).outlineStyle})")
    expect(gallery_state["src"] == hover_url and gallery_state["pressed"] == ["false", "true"] and gallery_state["focused"] and gallery_state["outline"] != "none", "Keyboard gallery selection or active/focus state failed: " + json.dumps(gallery_state))

    detail_theme_signatures = cdp.evaluate("""
        (() => {
            const root=document.documentElement;
            const names=['--blue-deep','--panel-strong','--text'];
            const original=Object.fromEntries(names.map(name=>[name,root.style.getPropertyValue(name)]));
            const palettes=[['default','#c86f52','#fff8f3','#3d2925'],['pastel','#7968d8','#fbf8ff','#34304f'],['midnight','#8ca8ff','#171a2b','#f3f5ff']];
            const values=palettes.map(([name,accent,surface,text])=>{
                root.style.setProperty('--blue-deep',accent); root.style.setProperty('--panel-strong',surface); root.style.setProperty('--text',text);
                return {name,frame:getComputedStyle(document.querySelector('.shop-product-main-frame')).backgroundColor,action:getComputedStyle(document.querySelector('.shop-product-action')).backgroundColor,title:getComputedStyle(document.querySelector('.shop-product-information h1')).color};
            });
            names.forEach(name=>original[name] ? root.style.setProperty(name,original[name]) : root.style.removeProperty(name));
            return values;
        })()
    """)
    expect(len({item["frame"] for item in detail_theme_signatures}) == 3 and len({item["action"] for item in detail_theme_signatures}) == 3 and len({item["title"] for item in detail_theme_signatures}) == 3, "Default, Pastel, and Midnight palettes did not restyle Product Detail: " + json.dumps(detail_theme_signatures))
    capture_screenshot(cdp, "shop-d-product-detail-desktop.png")
    checks += 5

    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(storefront_fields["slug"]), "Boolean(document.querySelector('.shop-product-detail'))")
    external_detail = cdp.evaluate("""
        (() => {
            const detail=document.querySelector('.shop-product-detail');
            const action=detail.querySelector('.shop-product-action');
            return {href:action.href,target:action.target,rel:action.rel,addButtons:detail.querySelectorAll('[data-add-cart]').length,badges:[...detail.querySelectorAll('.shop-product-badge')].map(item=>item.textContent),cart:localStorage.getItem('dyndelShopCart')};
        })()
    """)
    expect(external_detail["href"] == storefront_fields["externalUrl"] and external_detail["target"] == "_blank" and set(external_detail["rel"].split()) == {"noopener", "noreferrer"}, "External Product Detail URL was not safely linked")
    expect(external_detail["addButtons"] == 0 and external_detail["badges"] == ["Sale", "Sold Out", "Limited"] and external_detail["cart"] == "[]", "External Product Detail exposed local cart behavior or incorrect badges")

    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(sold_internal_fields["slug"]), "Boolean(document.querySelector('.shop-product-detail'))")
    sold_detail = cdp.evaluate("({button:document.querySelector('.shop-product-action').textContent.trim(),disabled:document.querySelector('.shop-product-action').disabled,addButtons:document.querySelectorAll('[data-add-cart]').length,badges:[...document.querySelectorAll('.shop-product-detail-badges .shop-product-badge')].map(item=>item.textContent),cart:localStorage.getItem('dyndelShopCart')})")
    expect(sold_detail["button"] == "Sold out" and sold_detail["disabled"] and sold_detail["addButtons"] == 1 and sold_detail["badges"] == ["Sold Out", "Archive"] and sold_detail["cart"] == "[]", "Sold-out internal detail did not remain visible with a disabled purchase action")

    status, _ = api_request("product", "POST", {"id": sold_internal_id, "publicationStatus": "draft"}, session_id)
    expect(status == 200, "Could not set Product Detail fixture to draft")
    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(sold_internal_fields["slug"]), "Boolean(document.querySelector('.shop-product-state h1'))")
    draft_state = cdp.evaluate("({heading:document.querySelector('.shop-product-state h1').textContent,body:document.querySelector('.shop-product-state').textContent,title:document.title,rawError:document.body.innerText.includes('404')})")
    expect(draft_state["heading"] == "Artwork not found." and "Back to Store" in draft_state["body"] and draft_state["title"].startswith("Artwork not found") and not draft_state["rawError"], "Draft Product Detail did not return a friendly not-found state")
    status, _ = api_request("product", "POST", {"id": sold_internal_id, "publicationStatus": "published", "showWhenSoldOut": "0"}, session_id)
    expect(status == 200, "Could not set Product Detail fixture to unavailable")
    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(sold_internal_fields["slug"]), "Boolean(document.querySelector('.shop-product-state h1'))")
    expect(cdp.evaluate("document.querySelector('.shop-product-state h1').textContent") == "Artwork not found.", "Unavailable sold-out Product Detail was publicly rendered")
    cdp.navigate(BASE + "store.html?product=missing-" + token, "Boolean(document.querySelector('.shop-product-state h1'))")
    expect(cdp.evaluate("document.querySelector('.shop-product-state h1').textContent === 'Artwork not found.' && !document.body.innerText.includes('Product not found.')") is True, "Invalid Product Detail exposed a raw API error")
    checks += 8

    status, _ = api_request("delete-product", "POST", {"id": sold_internal_id}, session_id)
    expect(status == 200, "Could not remove disposable sold-out Product Detail fixture")
    fixture_ids.remove(sold_internal_id)

    cdp.call("Emulation.setDeviceMetricsOverride", {
        "width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True,
    })

    cdp.navigate(BASE + "store.html?product=" + urllib.parse.quote(v2_fields["slug"]), "document.querySelectorAll('.shop-product-thumbnail').length === 2")
    mobile_detail = cdp.evaluate("""
        (() => {
            const detail=document.querySelector('.shop-product-detail');
            const thumb=detail.querySelector('.shop-product-thumbnail').getBoundingClientRect();
            const action=detail.querySelector('.shop-product-action').getBoundingClientRect();
            const image=detail.querySelector('.shop-product-main-image');
            return {
                columns:getComputedStyle(detail).gridTemplateColumns.split(' ').length,
                thumbWidth:thumb.width,thumbHeight:thumb.height,actionHeight:action.height,
                imageLoaded:image.complete && image.naturalWidth > 0,
                bannerDisplay:getComputedStyle(document.querySelector('.shop-banner')).display,
                cartVisible:getComputedStyle(document.querySelector('[data-open-cart]')).visibility,
                menuDisplay:getComputedStyle(document.querySelector('.menu-toggle')).display,
                mascotDisplay:getComputedStyle(document.querySelector('.contact-mascot')).display,
                overflow:document.documentElement.scrollWidth > innerWidth
            };
        })()
    """)
    expect(mobile_detail["columns"] == 1 and mobile_detail["thumbWidth"] >= 44 and mobile_detail["thumbHeight"] >= 44 and mobile_detail["actionHeight"] >= 44 and mobile_detail["imageLoaded"], "Mobile Product Detail did not stack with usable gallery/action targets: " + json.dumps(mobile_detail))
    expect(mobile_detail["bannerDisplay"] == "none" and mobile_detail["cartVisible"] == "visible" and mobile_detail["menuDisplay"] != "none" and mobile_detail["mascotDisplay"] == "none" and not mobile_detail["overflow"], "Mobile Product Detail navigation, decoration, banner, or overflow regressed")
    cdp.evaluate(f"localStorage.setItem('dyndelShopCart', JSON.stringify([{{id:{normal_product['id']},quantity:1}}])); location.reload()")
    cdp.wait_for("document.querySelectorAll('.shop-product-thumbnail').length === 2 && document.querySelector('[data-cart-count]').textContent === '1'", 15)
    mobile_detail_before_cart = cdp.evaluate("(() => { const detail=document.querySelector('.shop-product-detail').getBoundingClientRect(); const gallery=document.querySelector('.shop-product-gallery').getBoundingClientRect(); const header=document.querySelector('.header').getBoundingClientRect(); return {detail:{x:detail.x,y:detail.y,width:detail.width},gallery:{x:gallery.x,y:gallery.y,width:gallery.width},header:{x:header.x,y:header.y,width:header.width}}; })()")
    cdp.evaluate("document.querySelector('[data-open-cart]').click()")
    cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && Number(getComputedStyle(document.querySelector('[data-cart-backdrop]')).opacity) === 1 && Boolean(document.querySelector('.shop-cart-item')) && document.activeElement === document.querySelector('[data-close-cart]')", 15)
    mobile_detail_cart = cdp.evaluate("(() => { const detail=document.querySelector('.shop-product-detail').getBoundingClientRect(); const gallery=document.querySelector('.shop-product-gallery').getBoundingClientRect(); const header=document.querySelector('.header').getBoundingClientRect(); const drawer=document.querySelector('[data-cart-panel]').getBoundingClientRect(); return {detail:{x:detail.x,y:detail.y,width:detail.width},gallery:{x:gallery.x,y:gallery.y,width:gallery.width},header:{x:header.x,y:header.y,width:header.width},drawerWidth:drawer.width,drawerRight:drawer.right,item:document.querySelector('.shop-cart-item strong').textContent,overflow:document.documentElement.scrollWidth>innerWidth}; })()")
    expect(mobile_detail_cart["detail"] == mobile_detail_before_cart["detail"] and mobile_detail_cart["gallery"] == mobile_detail_before_cart["gallery"] and mobile_detail_cart["header"] == mobile_detail_before_cart["header"], "Opening Cart reflowed the mobile Product Detail/gallery: " + json.dumps({"before": mobile_detail_before_cart, "open": mobile_detail_cart}))
    expect(360 <= mobile_detail_cart["drawerWidth"] <= 390 and mobile_detail_cart["drawerRight"] == 390 and mobile_detail_cart["item"] == normal_product["title"] and not mobile_detail_cart["overflow"], "Mobile Product Detail Cart overlay was incorrect: " + json.dumps(mobile_detail_cart))
    capture_screenshot(cdp, "shop-e-product-detail-cart-mobile.png", full_page=False)
    cdp.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", "key": "Escape", "code": "Escape", "windowsVirtualKeyCode": 27})
    cdp.call("Input.dispatchKeyEvent", {"type": "keyUp", "key": "Escape", "code": "Escape", "windowsVirtualKeyCode": 27})
    cdp.wait_for("document.querySelector('[data-cart-region]').hidden && document.activeElement === document.querySelector('[data-open-cart]')", 5)
    cdp.evaluate("localStorage.removeItem('dyndelShopCart')")
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    cdp.wait_for("document.querySelector('.nav').classList.contains('open') && getComputedStyle(document.querySelector('.nav')).visibility === 'visible' && Number(getComputedStyle(document.querySelector('.nav')).opacity) === 1", 5)
    cdp.evaluate("document.querySelector('.menu-toggle').click()")
    cdp.wait_for("!document.querySelector('.nav').classList.contains('open') && getComputedStyle(document.querySelector('.nav')).visibility === 'hidden'", 5)
    capture_screenshot(cdp, "shop-d-product-detail-mobile.png")
    checks += 5

    cdp.navigate(BASE + "store.html", "document.querySelectorAll('.shop-product').length === 5")
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
    expect(cdp.evaluate("document.querySelector('[data-works-toggle]').getAttribute('aria-current') === 'page' && document.querySelector('[data-works-section=\"illustration\"]').getAttribute('aria-current') === 'page' && document.querySelector('[data-open-cart]').tabIndex === -1") is True, "Illustration did not retain Works active state with hidden Cart")

    status, public_content = api_request("content")
    expect(status == 200 and public_content.get("entries"), "No published Story was available for regression")
    published_story = public_content["entries"][0]
    cdp.navigate(BASE + "stories.php")
    cdp.wait_for("document.querySelector('[data-stories-results]').getAttribute('aria-busy') === 'false'", 15)
    expect(cdp.evaluate("document.querySelectorAll('[data-stories-list] .story-card').length") == len(public_content["entries"]), "Stories archive did not render published entries")
    expect(cdp.evaluate("document.querySelector('[data-nav-section=\"stories\"]').getAttribute('aria-current') === 'page' && document.querySelector('[data-open-cart]').tabIndex === -1") is True, "Stories navigation state or hidden Cart regressed")
    cdp.navigate(BASE + "story.php?slug=" + urllib.parse.quote(published_story["slug"]))
    expect(cdp.evaluate("document.querySelector('.story-article h1')?.textContent || ''") == published_story["title"], "Story reader did not render the selected published story")
    expect(cdp.evaluate("document.querySelector('[data-nav-section=\"stories\"]').getAttribute('aria-current') === 'page' && document.querySelector('[data-open-cart]').tabIndex === -1") is True, "Story reader navigation state or hidden Cart regressed")

    cdp.navigate(BASE + "index.html#contact")
    cdp.wait_for("location.hash === '#contact' && document.querySelector('#contact') && document.querySelectorAll('.home-gallery .gallery-card').length > 0", 15)
    contact_state = cdp.evaluate("""
        (() => {
            const contact=document.querySelector('#contact').getBoundingClientRect();
            const header=document.querySelector('.header').getBoundingClientRect();
            const current=[...document.querySelectorAll('.nav-list > li > [aria-current]')].map(item=>item.dataset.navSection || 'works');
            const cart=document.querySelector('[data-open-cart]');
            return {hash:location.hash, contactVisible:contact.top >= header.height - 6 && contact.top < innerHeight, projects:document.querySelectorAll('.home-gallery .gallery-card').length,current,contactCurrent:document.querySelector('[data-nav-section="contact"]').getAttribute('aria-current'),cartHidden:getComputedStyle(cart).visibility === 'hidden' && cart.tabIndex === -1,compact:document.querySelector('.header').classList.contains('is-compact')};
        })()
    """)
    expect(contact_state["hash"] == "#contact" and contact_state["contactVisible"] and contact_state["projects"] > 0 and contact_state["current"] == ["contact"] and contact_state["contactCurrent"] == "location" and contact_state["cartHidden"] and contact_state["compact"], "Contact deep link, active state, compact header, or homepage project gallery regressed: " + json.dumps(contact_state))
    checks += 8

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

    # Shop F2 Checkout V1: customer flow, server quote hydration, pending handoff,
    # responsive layout, themes, and temporary shipping/digital fixtures.
    checkout_run = secrets.token_hex(4)
    checkout_zone_id = None
    checkout_digital_id = None
    checkout_order_id = None
    try:
        cdp.call("Emulation.setDeviceMetricsOverride", {
            "width": 1440, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
        })
        cdp.navigate(BASE + "checkout.html")
        cdp.evaluate("localStorage.setItem('dyndelShopCart','[]'); location.reload()")
        cdp.wait_for("!document.querySelector('[data-checkout-empty]').hidden", 15)
        empty_checkout = cdp.evaluate("""
            (() => ({
                title:document.querySelector('[data-checkout-empty] h2').textContent,
                formHidden:document.querySelector('[data-checkout-form]').hidden,
                storeCurrent:document.querySelector('.nav [data-nav-section="store"]').getAttribute('aria-current'),
                overflow:document.documentElement.scrollWidth > innerWidth
            }))()
        """)
        expect(empty_checkout == {"title": "Your Cart is empty.", "formHidden": True, "storeCurrent": "page", "overflow": False}, "Checkout empty state or Store navigation context failed: " + json.dumps(empty_checkout))

        cdp.evaluate("localStorage.setItem('dyndelShopCart',JSON.stringify([{id:1,quantity:1}]))")
        cdp.navigate(BASE + "store.html")
        cdp.wait_for("document.querySelector('[data-product-id=\"1\"]') !== null", 15)
        cdp.evaluate("document.querySelector('[data-open-cart]').click()")
        cdp.wait_for("document.querySelector('[data-cart-region]').classList.contains('is-open') && !document.querySelector('[data-cart-checkout]').hidden", 15)
        cart_checkout = cdp.evaluate("""
            (() => {
                const link=document.querySelector('[data-cart-checkout]');
                return {label:link.textContent.trim(), target:new URL(link.href).pathname.endsWith('/checkout.html'), continueType:document.querySelector('[data-continue-shopping]').tagName};
            })()
        """)
        expect(cart_checkout == {"label": "CHECKOUT", "target": True, "continueType": "BUTTON"}, "Cart Drawer Checkout integration failed")
        cdp.evaluate("document.querySelector('[data-cart-checkout]').click()")
        cdp.wait_for("location.pathname.endsWith('/checkout.html') && !document.querySelector('[data-checkout-form]').hidden", 15)
        cdp.evaluate("document.querySelector('[data-checkout-country]').value='TH'; document.querySelector('[data-checkout-country]').dispatchEvent(new Event('change',{bubbles:true}))")
        cdp.wait_for("document.querySelector('[data-checkout-shipping-message]').textContent.includes('not available')", 15)
        unavailable = cdp.evaluate("({shipping:!document.querySelector('[data-checkout-shipping]').hidden, delivery:!document.querySelector('[data-checkout-delivery]').hidden, disabled:document.querySelector('[data-checkout-submit]').disabled, message:document.querySelector('[data-checkout-shipping-message]').textContent})")
        expect(unavailable["shipping"] and unavailable["delivery"] and unavailable["disabled"] and "not available" in unavailable["message"], "Unconfigured physical shipping did not block Checkout cleanly")

        checkout_zone_id = int(mysql_value(f"INSERT INTO shop_shipping_zones (code,name,is_rest_of_world,enabled,sort_order) VALUES ('browser-f2-{checkout_run}','Browser F2 Thailand',0,1,1); SELECT LAST_INSERT_ID();"))
        mysql_value(f"INSERT INTO shop_shipping_zone_countries (zone_id,country_code) VALUES ({checkout_zone_id},'TH');")
        checkout_method_id = int(mysql_value(f"INSERT INTO shop_shipping_methods (zone_id,code,name,price,currency,estimated_delivery_min,estimated_delivery_max,estimated_delivery_unit,enabled,sort_order) VALUES ({checkout_zone_id},'browser-standard-{checkout_run}','Standard International',12.00,'USD',7,14,'business_days',1,1); SELECT LAST_INSERT_ID();"))
        checkout_digital_id = int(mysql_value(f"INSERT INTO shop_products (sku,slug,title,short_description,description,product_type,image_url,price,sale_price,stock,publication_status,storefront_visible,show_when_sold_out,featured,sort_order,purchase_action,external_url,active) VALUES ('BROWSER-F2-DIGITAL-{checkout_run}','browser-f2-digital-{checkout_run}','Browser Digital','Digital test','Digital checkout browser fixture.','digital','img/icon.png',10.00,7.00,5,'published',1,1,0,9990,'internal',NULL,1); SELECT LAST_INSERT_ID();"))
        fixture_ids.append(checkout_digital_id)

        cdp.evaluate("document.querySelector('[data-checkout-country]').value='US'; document.querySelector('[data-checkout-country]').dispatchEvent(new Event('change',{bubbles:true})); document.querySelector('[data-checkout-country]').value='TH'; document.querySelector('[data-checkout-country]').dispatchEvent(new Event('change',{bubbles:true}))")
        cdp.wait_for("document.querySelectorAll('[data-checkout-methods] input').length === 1", 15)
        cdp.evaluate("document.querySelector('[data-checkout-methods] input').click()")
        cdp.wait_for("document.querySelector('[data-checkout-total]').textContent === '$30.00'", 15)
        physical_review = cdp.evaluate("""
            (() => {
                const layout=getComputedStyle(document.querySelector('[data-checkout-form]'));
                const summary=getComputedStyle(document.querySelector('.checkout-summary'));
                return {
                    items:document.querySelectorAll('[data-checkout-summary-items] .checkout-summary-item').length,
                    method:document.querySelector('.checkout-method-heading strong').textContent,
                    estimate:document.querySelector('.checkout-method small').textContent,
                    subtotal:document.querySelector('[data-checkout-subtotal]').textContent,
                    shipping:document.querySelector('[data-checkout-shipping-total]').textContent,
                    total:document.querySelector('[data-checkout-total]').textContent,
                    columns:layout.gridTemplateColumns.split(' ').length,
                    sticky:summary.position,
                    overflow:document.documentElement.scrollWidth > innerWidth
                };
            })()
        """)
        expect(physical_review["items"] == 1 and physical_review["method"] == "Standard International" and physical_review["estimate"] == "Estimated 7–14 business days" and physical_review["subtotal"] == "$18.00" and physical_review["shipping"] == "$12.00" and physical_review["total"] == "$30.00" and physical_review["columns"] == 2 and physical_review["sticky"] == "sticky" and not physical_review["overflow"], "Desktop physical order review, estimate, totals, or layout failed: " + json.dumps(physical_review))
        capture_screenshot(cdp, "shop-f2-checkout-desktop.png")

        cdp.evaluate("document.querySelector('[data-checkout-submit]').click()")
        cdp.wait_for("!document.querySelector('[data-checkout-errors]').hidden", 5)
        validation_focus = cdp.evaluate("({id:document.activeElement.id || '',errorFocus:document.activeElement === document.querySelector('[data-checkout-errors]'),invalid:document.activeElement.matches(':invalid'),message:document.querySelector('[data-checkout-errors]').textContent})")
        expect((validation_focus["id"] in ["checkout-name", "checkout-email", "checkout-address1", "checkout-city", "checkout-postal"] or validation_focus["errorFocus"] or validation_focus["invalid"]) and "required fields" in validation_focus["message"], "Checkout validation did not provide logical error focus: " + json.dumps(validation_focus))
        cdp.evaluate("""
            (() => {
                const f=document.querySelector('[data-checkout-form]');
                f.elements.customerName.value='Browser Guest';
                f.elements.customerEmail.value='browser@example.com';
                f.elements.customerPhone.value='+66 81 234 5678';
                f.elements.addressLine1.value='123 Browser Road';
                f.elements.addressLine2.value='Studio 4';
                f.elements.city.value='Bangkok';
                f.elements.region.value='Bangkok';
                f.elements.postalCode.value='10110';
                f.requestSubmit();
            })()
        """)
        cdp.wait_for("!document.querySelector('[data-checkout-handoff]').hidden", 15)
        handoff = cdp.evaluate("({heading:document.querySelector('[data-checkout-handoff] h2').textContent,copy:document.querySelector('[data-checkout-handoff]').textContent,cart:JSON.parse(localStorage.getItem('dyndelShopCart')),focused:document.activeElement === document.querySelector('[data-checkout-handoff]'),orderId:Number(document.querySelector('[data-checkout-handoff]').dataset.orderId)})")
        checkout_order_id = handoff["orderId"]
        expect(handoff["heading"] == "Order prepared." and "Payment integration will be added" in handoff["copy"] and "pending and unpaid" in handoff["copy"] and handoff["cart"] == [{"id": 1, "quantity": 1}] and handoff["focused"], "Payment-boundary handoff, focus, or Cart preservation failed")
        order_state = mysql_value(f"SELECT CONCAT(order_origin,':',status,':',payment_status,':',total) FROM shop_orders WHERE id={checkout_order_id};")
        expect(order_state == "checkout_v2:pending:unpaid:30.00" and mysql_value("SELECT stock FROM shop_products WHERE id=1;") == "12", "Browser handoff order lifecycle or stock safety failed")

        cdp.evaluate(f"localStorage.setItem('dyndelShopCart',JSON.stringify([{{id:{checkout_digital_id},quantity:1}}])); location.href='checkout.html'")
        cdp.wait_for("!document.querySelector('[data-checkout-form]').hidden && document.querySelector('[data-checkout-total]').textContent === '$7.00'", 15)
        digital_checkout = cdp.evaluate("({shipping:document.querySelector('[data-checkout-shipping]').hidden,delivery:document.querySelector('[data-checkout-delivery]').hidden,shippingRow:document.querySelector('[data-checkout-shipping-row]').hidden,items:document.querySelectorAll('.checkout-summary-item').length,total:document.querySelector('[data-checkout-total]').textContent})")
        expect(digital_checkout == {"shipping": True, "delivery": True, "shippingRow": True, "items": 1, "total": "$7.00"}, "Digital-only Checkout displayed shipping or incorrect totals")

        cdp.evaluate(f"localStorage.setItem('dyndelShopCart',JSON.stringify([{{id:1,quantity:1}},{{id:{checkout_digital_id},quantity:1}}])); location.reload()")
        cdp.wait_for("!document.querySelector('[data-checkout-form]').hidden && document.querySelectorAll('.checkout-summary-item').length === 2", 15)
        expect(cdp.evaluate("!document.querySelector('[data-checkout-shipping]').hidden && !document.querySelector('[data-checkout-delivery]').hidden") is True, "Mixed Checkout did not require shipping")

        theme_signatures = cdp.evaluate("""
            (() => {
                const palettes=[
                    {accentColor:'#c86f52',pageBackground:'#fff0e8',surfaceColor:'#fff8f3',primaryText:'#3d2925',buttonRadius:'999px',galleryLayout:'uniform',galleryEdge:'rounded'},
                    {accentColor:'#d58aaa',pageBackground:'#fff4f8',surfaceColor:'#fffafd',primaryText:'#513747',buttonRadius:'8px',galleryLayout:'clean',galleryEdge:'slight'},
                    {accentColor:'#8da2ff',pageBackground:'#171925',surfaceColor:'#242738',primaryText:'#f3efff',buttonRadius:'4px',galleryLayout:'editorial',galleryEdge:'square'}
                ];
                return palettes.map(theme => {
                    applyPublicTheme(theme);
                    return {background:getComputedStyle(document.body).backgroundColor,summary:getComputedStyle(document.querySelector('.checkout-summary')).backgroundColor,button:getComputedStyle(document.querySelector('[data-checkout-submit]')).backgroundColor};
                });
            })()
        """)
        expect(len({item["background"] for item in theme_signatures}) == 3 and len({item["summary"] for item in theme_signatures}) == 3 and len({item["button"] for item in theme_signatures}) == 3, "Default, Pastel, and Midnight palettes did not restyle Checkout: " + json.dumps(theme_signatures))
        cdp.evaluate("fetch('api/index.php?action=theme').then(r=>r.json()).then(d=>applyPublicTheme(d.theme))", await_promise=True)

        cdp.call("Emulation.setDeviceMetricsOverride", {"width": 1200, "height": 800, "deviceScaleFactor": 1, "mobile": False})
        desktop_1200 = cdp.evaluate("({scrollWidth:document.documentElement.scrollWidth,innerWidth,columns:getComputedStyle(document.querySelector('[data-checkout-form]')).gridTemplateColumns.split(' ').length})")
        expect(desktop_1200["scrollWidth"] <= desktop_1200["innerWidth"] and desktop_1200["columns"] == 2, "1200px Checkout layout overflowed or lost two-column layout: " + json.dumps(desktop_1200))
        cdp.call("Emulation.setDeviceMetricsOverride", {"width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True})
        mobile_checkout = cdp.evaluate("""
            (() => {
                const form=document.querySelector('[data-checkout-form]');
                document.querySelector('.menu-toggle').click();
                return {width:innerWidth,columns:getComputedStyle(form).gridTemplateColumns.split(' ').length,overflow:document.documentElement.scrollWidth > innerWidth,navOpen:document.querySelector('.nav').classList.contains('open'),storeCurrent:document.querySelector('.nav [data-nav-section="store"]').getAttribute('aria-current')};
            })()
        """)
        expect(mobile_checkout == {"width": 390, "columns": 1, "overflow": False, "navOpen": True, "storeCurrent": "page"}, "390px Checkout layout or mobile navigation failed: " + json.dumps(mobile_checkout))
        cdp.evaluate("document.querySelector('.menu-toggle').click()")
        cdp.wait_for("!document.querySelector('.nav').classList.contains('open') && getComputedStyle(document.querySelector('.nav')).visibility === 'hidden'", 5)
        cdp.call("Emulation.setDeviceMetricsOverride", {"width": 390, "height": 640, "deviceScaleFactor": 1, "mobile": True})
        expect(cdp.evaluate("document.documentElement.scrollWidth <= innerWidth && document.querySelector('[data-checkout-submit]').getBoundingClientRect().width <= innerWidth - 32") is True, "Short-height mobile Checkout clipped or overflowed")
        capture_screenshot(cdp, "shop-f2-checkout-mobile.png")
        checks += 15
    finally:
        if checkout_order_id:
            mysql_value(f"DELETE FROM shop_orders WHERE id={checkout_order_id};")
        if checkout_digital_id:
            if checkout_digital_id in fixture_ids:
                fixture_ids.remove(checkout_digital_id)
            mysql_value(f"DELETE FROM shop_products WHERE id={checkout_digital_id};")
        if checkout_zone_id:
            mysql_value(f"DELETE FROM shop_shipping_zones WHERE id={checkout_zone_id};")

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
