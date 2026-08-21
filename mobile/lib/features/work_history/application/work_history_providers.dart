import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_work_history_repository.dart';
import '../data/mock_work_history_repository.dart';
import '../data/work_history_repository.dart';
import '../domain/work_history_models.dart';

final workHistoryRepositoryProvider = Provider<WorkHistoryRepository>((ref) {
  if (AppConfig.useMockApi) return MockWorkHistoryRepository();
  return ApiWorkHistoryRepository(ref.watch(apiClientProvider));
});

final workHistoryProvider =
    FutureProvider.autoDispose<List<WorkOrderHistoryItem>>((ref) {
  return ref.watch(workHistoryRepositoryProvider).fetchHistory();
});
