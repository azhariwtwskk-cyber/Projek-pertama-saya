import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Wraps [FlutterSecureStorage] (Keychain on iOS, EncryptedSharedPreferences
/// on Android) for everything that must never live in plain SharedPreferences:
/// the access token, the refresh token, and their expiry timestamps.
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
  static const _accessTokenExpiryKey = 'cpmspro.auth.access_token_expiry';
  static const _refreshTokenExpiryKey = 'cpmspro.auth.refresh_token_expiry';
  static const _rememberedUsernameKey = 'cpmspro.auth.remembered_username';

  Future<void> saveSession({
    required String accessToken,
    required String refreshToken,
    required DateTime accessExpiresAt,
    required DateTime refreshExpiresAt,
  }) async {
    await Future.wait([
      _storage.write(key: _accessTokenKey, value: accessToken),
      _storage.write(key: _refreshTokenKey, value: refreshToken),
      _storage.write(key: _accessTokenExpiryKey, value: accessExpiresAt.toIso8601String()),
      _storage.write(key: _refreshTokenExpiryKey, value: refreshExpiresAt.toIso8601String()),
    ]);
  }

  /// Persists a rotated token pair from a successful refresh call. Same
  /// shape as [saveSession] — kept as a separate, identically-named-intent
  /// method so call sites read clearly (`saveSession` after login,
  /// `saveRefreshedSession` after a silent refresh), even though the
  /// implementation is currently the same set of writes.
  Future<void> saveRefreshedSession({
    required String accessToken,
    required String refreshToken,
    required DateTime accessExpiresAt,
    required DateTime refreshExpiresAt,
  }) =>
      saveSession(
        accessToken: accessToken,
        refreshToken: refreshToken,
        accessExpiresAt: accessExpiresAt,
        refreshExpiresAt: refreshExpiresAt,
      );

  Future<String?> get accessToken => _storage.read(key: _accessTokenKey);
  Future<String?> get refreshToken => _storage.read(key: _refreshTokenKey);

  Future<DateTime?> get accessTokenExpiry async {
    final raw = await _storage.read(key: _accessTokenExpiryKey);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<DateTime?> get refreshTokenExpiry async {
    final raw = await _storage.read(key: _refreshTokenExpiryKey);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> clearSession() async {
    await Future.wait([
      _storage.delete(key: _accessTokenKey),
      _storage.delete(key: _refreshTokenKey),
      _storage.delete(key: _accessTokenExpiryKey),
      _storage.delete(key: _refreshTokenExpiryKey),
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
