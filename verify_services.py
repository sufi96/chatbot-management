import urllib.request
import json

def test_endpoint(name, url):
    try:
        req = urllib.request.urlopen(url, timeout=5)
        status = req.getcode()
        body = req.read().decode('utf-8')
        print(f"[SUCCESS] {name} ({url}) -> Status: {status}, Bytes: {len(body)}")
        if "application/json" in req.headers.get("Content-Type", ""):
            print("         Data preview:", body[:120])
    except Exception as e:
        print(f"[FAIL] {name} ({url}) -> Error: {e}")

if __name__ == "__main__":
    print("--- Verifying Services ---")
    test_endpoint("FastAPI Health", "http://127.0.0.1:8000/health")
    test_endpoint("FastAPI Bot Config", "http://127.0.0.1:8000/api/v1/bot/bot_demo_default/config")
    test_endpoint("FastAPI Widget Script", "http://127.0.0.1:8000/widget.js")
    test_endpoint("PHP Admin Dashboard", "http://127.0.0.1:8080/")
    test_endpoint("PHP Bot Embed Screen", "http://127.0.0.1:8080/bots/bot_demo_default/embed")
