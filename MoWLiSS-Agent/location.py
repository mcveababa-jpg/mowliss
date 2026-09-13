import requests

from common import log_audit

# ip-api.com is free and keyless but only approximates location from the IP
# address's registered region - there is no GPS on a desktop/laptop, so this
# is never more precise than "which city/ISP this connection is routed through".


def get_location():
    try:
        resp = requests.get("http://ip-api.com/json/", timeout=5)
        data = resp.json()
        if data.get("status") != "success":
            return None
        label = f"{data.get('city', '?')}, {data.get('regionName', '?')}, {data.get('country', '?')}"
        return {"lat": data.get("lat"), "lng": data.get("lon"), "label": label}
    except Exception as e:
        log_audit("location_error", {"error": str(e)})
        return None
