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

class AuthState {
  const AuthState({required this.status, this.user, this.errorMessage});

  final AuthStatus status;
  final StaffUser? user;
  final String? errorMessage;

  static const initial = AuthState(status: AuthStatus.unknown);

  AuthState copyWith({AuthStatus? status, StaffUser? user, String? errorMessage}) => AuthState(
        status: status ?? this.status,
        user: user ?? this.user,
        errorMessage: errorMessage,
      );
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(this._repository, this._secureStorage) : super(AuthState.initial) {
    _restoreSession();
  }

  final AuthRepository _repository;
  final SecureStorageService _secureStorage;

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
      final user = await _repository.fetchProfile();
      state = state.copyWith(status: AuthStatus.authenticated, user: user);
    } catch (_) {
      // Report unauthenticated first — clearing the (possibly already
      // broken) secure storage is best-effort cleanup and must never be
      // able to block the state transition that unblocks the UI.
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
        expiresAt: result.expiresAt,
        deviceSessionId: result.deviceSessionId,
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

  /// Invoked by [ApiClient] when a 401 survives a refresh attempt.
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
  ref.watch(apiClientProvider).onSessionExpired = () async => controller.forceLogout();
  return controller;
});

final currentStaffUserProvider = Provider<StaffUser?>((ref) => ref.watch(authControllerProvider).user);

final rememberedUsernameProvider = FutureProvider<String?>((ref) {
  return ref.watch(secureStorageProvider).rememberedUsername;
});
