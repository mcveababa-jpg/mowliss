import asyncio

from common import log_audit

# Only genuine on-device positioning is ever reported as a device's location.
#
# Windows Location Services cross-references nearby Wi-Fi networks (and GPS
# on hardware that has it) against Microsoft's positioning database - when it
# has a usable fix this is typically accurate to tens or a few hundred
# metres. But WLS can itself silently degrade to coarse, cell-tower/IP-level
# positioning when no Wi-Fi signal is visible, so a reported accuracy worse
# than MAX_ACCEPTABLE_ACCURACY_M is treated the same as no fix at all.
#
# An IP-geolocation fallback (ip-api.com) used to sit behind this and report
# a location whenever WLS was off/denied/unavailable. That was removed: IP
# geolocation only resolves to the ISP's registered routing point, which in
# Papua New Guinea in particular is often a single city (e.g. Port Moresby)
# regardless of the subscriber's actual location. Reporting that as "the
# device's location" is a guess dressed up as a location fix, not a real
# one - for a lost/stolen-device workflow that's actively misleading, so no
# location is reported at all rather than a wrong one.

MAX_ACCEPTABLE_ACCURACY_M = 500

# Windows Location Services can hang rather than fail when it can't get a fix
# (e.g. no Wi-Fi networks visible, indoors, service still initializing) - it's
# not guaranteed to ever resolve. Bounding it here means a bad fix attempt
# costs at most this many seconds instead of freezing whatever called us.
LOCATE_TIMEOUT_S = 8


def get_location():
    """Returns (location_dict_or_None, status_string).

    status_string is always populated, even on failure - it's sent back to the
    server so *why* a device has no location fix is visible from the admin
    dashboard, without needing hands-on access to the physical machine to read
    its local audit log.
    """
    try:
        from winsdk.windows.devices.geolocation import Geolocator, GeolocationAccessStatus

        async def _locate():
            status = await Geolocator.request_access_async()
            if status != GeolocationAccessStatus.ALLOWED:
                return None, f"access_{status.name.lower()}"

            locator = Geolocator()
            pos = await locator.get_geoposition_async()
            coord = pos.coordinate
            accuracy_m = coord.accuracy

            if accuracy_m is None:
                return None, "no_accuracy_reported"

            if accuracy_m > MAX_ACCEPTABLE_ACCURACY_M:
                return None, f"too_coarse_{int(accuracy_m)}m"

            return {
                "lat": coord.point.position.latitude,
                "lng": coord.point.position.longitude,
                "label": f"Device-positioned, accuracy ~{int(accuracy_m)}m (Windows Location Services)",
            }, "ok"

        async def _locate_bounded():
            return await asyncio.wait_for(_locate(), timeout=LOCATE_TIMEOUT_S)

        loc, status = asyncio.run(_locate_bounded())
        if status != "ok":
            log_audit("location_not_used", {"status": status})
        return loc, status
    except (asyncio.TimeoutError, TimeoutError):
        log_audit("location_timed_out", {"timeout_s": LOCATE_TIMEOUT_S})
        return None, "timed_out"
    except ModuleNotFoundError as e:
        log_audit("location_unavailable", {"error": str(e)})
        return None, "winsdk_not_available"
    except Exception as e:
        log_audit("location_unavailable", {"error": str(e)})
        return None, f"error_{type(e).__name__}"
