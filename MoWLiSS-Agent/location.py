import asyncio

import requests

from common import log_audit

# Two location sources, tried in order of accuracy:
#
# 1. Windows Location Services - when the OS has location turned on and the
#    device has usable signal (mainly nearby Wi-Fi networks cross-referenced
#    against Microsoft's positioning database, plus GPS on hardware that has
#    it), this is typically accurate to tens or a few hundred metres.
# 2. ip-api.com IP geolocation (free, keyless) - the fallback when Windows
#    location is off, denied, or unavailable (e.g. a wired-only desktop with
#    no Wi-Fi hardware). This only resolves to the ISP's registered routing
#    point, which in Papua New Guinea in particular is often a single city
#    (e.g. Port Moresby) regardless of the subscriber's actual location - it
#    is a rough approximation, not a precise position.
#
# There is no GPS on a typical desktop/laptop, so source 1 is itself not
# GPS-grade unless the specific hardware has a GPS chip - this is always the
# best available approximation, never a guarantee of precision.


def _get_windows_location():
    try:
        from winsdk.windows.devices.geolocation import Geolocator, GeolocationAccessStatus

        async def _locate():
            status = await Geolocator.request_access_async()
            if status != GeolocationAccessStatus.ALLOWED:
                return None

            locator = Geolocator()
            pos = await locator.get_geoposition_async()
            coord = pos.coordinate
            accuracy_m = coord.accuracy
            return {
                "lat": coord.point.position.latitude,
                "lng": coord.point.position.longitude,
                "label": f"Device-positioned, accuracy ~{int(accuracy_m)}m (Windows Location Services)",
            }

        return asyncio.run(_locate())
    except Exception as e:
        log_audit("windows_location_unavailable", {"error": str(e)})
        return None


def _get_ip_location():
    try:
        resp = requests.get("http://ip-api.com/json/", timeout=5)
        data = resp.json()
        if data.get("status") != "success":
            return None
        label = f"{data.get('city', '?')}, {data.get('regionName', '?')}, {data.get('country', '?')} (approximate, IP-based)"
        return {"lat": data.get("lat"), "lng": data.get("lon"), "label": label}
    except Exception as e:
        log_audit("location_error", {"error": str(e)})
        return None


def get_location():
    return _get_windows_location() or _get_ip_location()
