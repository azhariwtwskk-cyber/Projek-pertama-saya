import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Wraps [FlutterSecureStorage] (Keychain on iOS, EncryptedSharedPreferences
/// on Android) for everything that must never live in plain SharedPreferences:
/// auth/refresh tokens, the active staff_id/property_id issued by the server,
/// and the device session id.
class SecureStorageService {
  SecureStorageService({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
            );

  final FlutterSecureStorage _storage;

  static const _accessTokenKey = 'cpmspro.auth.access_token';
  static const _refreshTokenKey = 'cpmspro.auth.refresh_token';
  static const _tokenExpiryKey = 'cpmspro.auth.token_expiry';
  static const _deviceSessionIdKey = 'cpmspro.auth.device_session_id';
  static const _rememberedUsernameKey = 'cpmspro.auth.remembered_username';

  Future<void> saveSession({
    required String accessToken,
    required String refreshToken,
    required DateTime expiresAt,
    required String deviceSessionId,
  }) async {
    await Future.wait([
      _storage.write(key: _accessTokenKey, value: accessToken),
      _storage.write(key: _refreshTokenKey, value: refreshToken),
      _storage.write(key: _tokenExpiryKey, value: expiresAt.toIso8601String()),
      _storage.write(key: _deviceSessionIdKey, value: deviceSessionId),
    ]);
  }

  Future<String?> get accessToken => _storage.read(key: _accessTokenKey);
  Future<String?> get refreshToken => _storage.read(key: _refreshTokenKey);
  Future<String?> get deviceSessionId => _storage.read(key: _deviceSessionIdKey);

  Future<DateTime?> get tokenExpiry async {
    final raw = await _storage.read(key: _tokenExpiryKey);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> clearSession() async {
    await Future.wait([
      _storage.delete(key: _accessTokenKey),
      _storage.delete(key: _refreshTokenKey),
      _storage.delete(key: _tokenExpiryKey),
      _storage.delete(key: _deviceSessionIdKey),
    ]);
  }

  Future<void> saveRememberedUsername(String? username) async {
    if (username == null || username.isEmpty) {
      await _storage.delete(key: _rememberedUsernameKey);
    } else {
      await _storage.write(key: _rememberedUsernameKey, value: username);
    }
  }

  Future<String?> get rememberedUsername => _storage.read(key: _rememberedUsernameKey);
}
