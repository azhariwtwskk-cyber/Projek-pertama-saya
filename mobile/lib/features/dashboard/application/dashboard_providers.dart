import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_dashboard_repository.dart';
import '../data/dashboard_repository.dart';
import '../data/mock_dashboard_repository.dart';
import '../domain/dashboard_models.dart';

final dashboardRepositoryProvider = Provider<DashboardRepository>((ref) {
  if (AppConfig.useMockApi) return MockDashboardRepository();
  return ApiDashboardRepository(ref.watch(apiClientProvider));
});

final dashboardDataProvider = FutureProvider.autoDispose<DashboardData>((ref) {
  return ref.watch(dashboardRepositoryProvider).fetchDashboard();
});
