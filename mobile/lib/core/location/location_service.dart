import 'package:geolocator/geolocator.dart';

class LocationPermissionDenied implements Exception {}
class LocationServiceDisabled implements Exception {}

/// Thin wrapper around geolocator used ONLY for attendance clock-in/out
/// and evidence-photo GPS tags (sections 20/34) — the app never subscribes
/// to a continuous location stream, so battery/GPS usage stays minimal on
/// low-end site-worker phones.
class LocationService {
  const LocationService();

  Future<Position> getCurrentPosition() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw LocationServiceDisabled();
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      throw LocationPermissionDenied();
    }
    return Geolocator.getCurrentPosition(desiredAccuracy: LocationAccuracy.high);
  }
}
