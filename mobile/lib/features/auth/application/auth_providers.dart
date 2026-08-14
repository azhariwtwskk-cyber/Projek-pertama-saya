import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/config/app_config.dart';
import '../../../core/storage/secure_storage_service.dart';
import '../data/api_auth_repository.dart';
import '../data/auth_repository.dart';
import '../data/mock_auth_repository.dart';
import '../domain/staff_user.dart';

final secureStorageProvider = Provider<SecureStorageService>((ref) => SecureStorageService());

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(secureStorage: ref.watch(secureStorageProvider));
});

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  if (AppConfig.useMockApi) return MockAuthRepository();
  return ApiAuthRepository(ref.watch(apiClientProvider));
});

enum AuthStatus { unknown, authenticating, authenticated, unauthenticated }

/// Sentinel distinguishing "no value passed" from "explicitly passed null"
/// for [AuthState.copyWith]'s `user` parameter.
const _unset = Object();

class AuthState {
  const AuthState({required this.status, this.user, this.errorMessage});

  final AuthStatus status;
  final StaffUser? user;
  final String? errorMessage;

  static const initial = AuthState(status: AuthStatus.unknown);

  AuthState copyWith({AuthStatus? status, Object? user = _unset, String? errorMessage}) => AuthState(
        status: status ?? this.status,
        // `user ?? this.user` would silently keep the old user whenever a
        // caller passes `user: null` to clear it (e.g. forceLogout,
        // logout) -- an `identical(_unset)` check is needed so an explicit
        // null is honored instead of being treated as "not provided".
        user: identical(user, _unset) ? this.user : user as StaffUser?,
        errorMessage: errorMessage,
      );
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._repository, this._secureStorage) : super(AuthState.initial) {
    _restoreSession();
  }

  final AuthRepository _repository;
  final SecureStorageService _secureStorage;

  /// Guards against concurrent refresh calls: if several requests 401 at
  /// once (e.g. right after the app resumes with an expired access
  /// token), they all await this one in-flight refresh instead of each
  /// firing their own `POST /auth/refresh.php` — the backend rotates the
  /// refresh token on every call, so a second concurrent call using the
  /// now-stale stored token would otherwise fail needlessly.
  Future<String?>? _refreshInFlight;

  Future<void> _restoreSession() async {
    // Deliberately catches everything, including failures reading secure
    // storage itself (corrupted keychain, first-run platform quirks): the
    // app must never get stuck showing a blank screen because session
    // restore hung or threw — falling through to the login screen is
    // always a safe, recoverable default.
    try {
      final token = await _secureStorage.accessToken;
      if (token == null) {
        state = state.copyWith(status: AuthStatus.unauthenticated);
        return;
      }
      // No separate "is the access token expired?" check here on purpose:
      // fetchProfile() below goes through ApiClient, whose interceptor
      // already does the full 401 -> refresh -> retry dance (see
      // onTokenRefreshNeeded below) — restoring an expired-but-refreshable
      // session and restoring a still-valid one are the same code path.
      final user = await _repository.fetchProfile();
      state = state.copyWith(status: AuthStatus.authenticated, user: user);
    } catch (_) {
      if (state.status == AuthStatus.unauthenticated) {
        // ApiClient's onSessionExpired (forceLogout, below) already ran
        // as part of that failed refresh attempt and already set a
        // user-facing message — don't overwrite it.
        return;
      }
      state = state.copyWith(status: AuthStatus.unauthenticated);
      try {
        await _secureStorage.clearSession();
      } catch (_) {
        // Ignore: nothing more we can do if storage itself is unusable.
      }
    }
  }

  Future<bool> login({
    required String usernameOrEmail,
    required String password,
    required bool rememberMe,
  }) async {
    state = state.copyWith(status: AuthStatus.authenticating, errorMessage: null);
    try {
      final result = await _repository.login(usernameOrEmail: usernameOrEmail, password: password);
      await _secureStorage.saveSession(
        accessToken: result.accessToken,
        refreshToken: result.refreshToken,
        accessExpiresAt: result.accessExpiresAt,
        refreshExpiresAt: result.refreshExpiresAt,
      );
      await _secureStorage.saveRememberedUsername(rememberMe ? usernameOrEmail : null);
      state = state.copyWith(status: AuthStatus.authenticated, user: result.user);
      return true;
    } catch (e) {
      state = state.copyWith(status: AuthStatus.unauthenticated, errorMessage: e.toString());
      return false;
    }
  }

  Future<void> logout() async {
    try {
      await _repository.logout();
    } catch (_) {
      // Best-effort: still clear the local session even if the network
      // call fails, so a staff member can always sign out.
    }
    try {
      await _secureStorage.clearSession();
    } catch (_) {
      // Ignore: the state transition below must happen regardless.
    }
    state = state.copyWith(status: AuthStatus.unauthenticated, user: null);
  }

  /// Assigned to [ApiClient.onTokenRefreshNeeded]. Returns the new access
  /// token on success, or null if the session genuinely can't be
  /// refreshed (no stored refresh token, or the server rejected it as
  /// expired/revoked/already-used) — ApiClient treats null as "give up
  /// and call onSessionExpired," never retries on its own.
  Future<String?> refreshAccessToken() {
    return _refreshInFlight ??= _performRefresh().whenComplete(() {
      _refreshInFlight = null;
    });
  }

  Future<String?> _performRefresh() async {
    final storedRefreshToken = await _secureStorage.refreshToken;
    if (storedRefreshToken == null) return null;
    try {
      final result = await _repository.refresh(refreshToken: storedRefreshToken);
      await _secureStorage.saveRefreshedSession(
        accessToken: result.accessToken,
        refreshToken: result.refreshToken,
        accessExpiresAt: result.accessExpiresAt,
        refreshExpiresAt: result.refreshExpiresAt,
      );
      return result.accessToken;
    } catch (_) {
      // RefreshTokenInvalid or any other failure (offline, server error):
      // either way this refresh attempt didn't produce a usable token, so
      // report failure and let the caller (ApiClient) fall back to
      // onSessionExpired. We don't distinguish "definitely invalid" from
      // "network hiccup" here — both correctly result in the request that
      // triggered this failing too, which is the safe default.
      return null;
    }
  }

  /// Invoked by [ApiClient] when a 401 survives a refresh attempt (or the
  /// refresh attempt itself couldn't produce a token).
  void forceLogout() {
    _secureStorage.clearSession();
    state = state.copyWith(status: AuthStatus.unauthenticated, user: null, errorMessage: 'Session expired. Please login again.');
  }
}

final authControllerProvider = StateNotifierProvider<AuthController, AuthState>((ref) {
  final controller = AuthController(ref.watch(authRepositoryProvider), ref.watch(secureStorageProvider));
  // Wired here (rather than inside apiClientProvider) so the two
  // providers don't form a dependency cycle: ApiClient reports session
  // expiry up to whoever owns the session, and AuthController is that
  // owner.
  final client = ref.watch(apiClientProvider);
  client.onTokenRefreshNeeded = controller.refreshAccessToken;
  client.onSessionExpired = () async => controller.forceLogout();
  return controller;
});

final currentStaffUserProvider = Provider<StaffUser?>((ref) => ref.watch(authControllerProvider).user);

final rememberedUsernameProvider = FutureProvider<String?>((ref) {
  return ref.watch(secureStorageProvider).rememberedUsername;
});
