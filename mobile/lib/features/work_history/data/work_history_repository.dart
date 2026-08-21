import '../domain/work_history_models.dart';

abstract class WorkHistoryRepository {
  /// Work orders with real activity (completed, or with at least one
  /// linked Daily Work submission), including the real management
  /// verification decision — see `staff/work-history.php`.
  Future<List<WorkOrderHistoryItem>> fetchHistory();
}
