import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../../tasks/domain/task_models.dart';
import '../domain/dashboard_models.dart';
import 'dashboard_repository.dart';

class ApiDashboardRepository implements DashboardRepository {
  ApiDashboardRepository(this._client);

  final ApiClient _client;

  @override
  Future<DashboardData> fetchDashboard() {
    return _client.request(
      (dio) => dio.get(ApiEndpoints.staffDashboard),
      (data) {
        final root = _asMap(data);

        // Some CPMS API responses may wrap payload inside "data".
        final json = root['data'] is Map ? _asMap(root['data']) : root;

        /*
         * ============================================================
         * TASK COLLECTION
         * ============================================================
         *
         * Current CPMS backend may return:
         *   tasks
         *   pm_tasks
         *
         * Older mobile contract used:
         *   recent_tasks
         */
        final List<StaffTask> tasks = [];

        final taskRows = _asList(
          json['tasks'] ?? json['recent_tasks'],
        );

        for (final item in taskRows) {
          if (item is! Map) continue;

          try {
            tasks.add(
              StaffTask.fromJson(
                Map<String, dynamic>.from(item),
              ),
            );
          } catch (_) {
            // Ignore an incompatible task row instead of crashing
            // the entire Home dashboard.
          }
        }

        /*
         * ============================================================
         * OVERVIEW / KPI
         * ============================================================
         */

        final overviewJson = _asMap(json['today_overview']);
        final statsJson = _asMap(json['stats']);

        // The real `dashboard.php` reports `stats.workOrders`/`pmTasks`
        // (open-count style), not the older `total/completed/pending`
        // breakdown this KPI row was originally built around — fold the
        // real fields in as a best-effort mapping rather than leaving
        // them silently unmatched (they'd otherwise always render as 0
        // even when the backend has real counts).
        final totalTasks = _toInt(
          overviewJson['total_tasks'] ??
              statsJson['total_tasks'] ??
              statsJson['total'] ??
              ((statsJson['workOrders'] != null || statsJson['pmTasks'] != null)
                  ? _toInt(statsJson['workOrders']) +
                      _toInt(statsJson['pmTasks'])
                  : null) ??
              tasks.length,
        );

        final completed = _toInt(
          overviewJson['completed'] ??
              statsJson['completed'] ??
              statsJson['completed_tasks'],
        );

        final pending = _toInt(
          overviewJson['pending'] ??
              statsJson['pending'] ??
              statsJson['pending_tasks'] ??
              statsJson['workOrders'],
        );

        final overdue = _toInt(
          overviewJson['overdue'] ??
              statsJson['overdue'] ??
              statsJson['overdue_tasks'],
        );

        /*
         * ============================================================
         * PRIORITY TASK
         * ============================================================
         */

        StaffTask? priorityTask;

        if (json['priority_task'] is Map) {
          try {
            priorityTask = StaffTask.fromJson(
              Map<String, dynamic>.from(
                json['priority_task'] as Map,
              ),
            );
          } catch (_) {
            priorityTask = null;
          }
        }

        // If backend does not provide priority_task,
        // use first valid task.
        priorityTask ??= tasks.isNotEmpty ? tasks.first : null;

        /*
         * ============================================================
         * ANNOUNCEMENTS
         * ============================================================
         */

        final announcements = <Announcement>[];

        for (final item in _asList(json['announcements'])) {
          if (item is! Map) continue;

          final row = Map<String, dynamic>.from(item);

          announcements.add(
            Announcement(
              id: _toString(row['id']),
              title: _toString(
                row['title'] ?? row['subject'],
              ),
              body: _toString(
                row['body'] ?? row['message'] ?? row['description'],
              ),
              postedAt: _toDateTime(
                row['posted_at'] ?? row['created_at'] ?? row['date'],
              ),
            ),
          );
        }

        return DashboardData(
          overview: TodayOverview(
            totalTasks: totalTasks,
            completed: completed,
            pending: pending,
            overdue: overdue,
          ),
          priorityTask: priorityTask,
          recentTasks: tasks,
          announcements: announcements,
        );
      },
    );
  }

  // ================================================================
  // SAFE PARSING HELPERS
  // ================================================================

  static Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) {
      return value;
    }

    if (value is Map) {
      return Map<String, dynamic>.from(value);
    }

    return <String, dynamic>{};
  }

  static List<dynamic> _asList(dynamic value) {
    if (value is List) {
      return value;
    }

    return <dynamic>[];
  }

  static int _toInt(dynamic value) {
    if (value == null) return 0;

    if (value is int) return value;

    if (value is num) {
      return value.toInt();
    }

    return int.tryParse(value.toString()) ?? 0;
  }

  static String _toString(dynamic value) {
    if (value == null) return '';

    return value.toString();
  }

  static DateTime _toDateTime(dynamic value) {
    if (value == null) {
      return DateTime.now();
    }

    if (value is DateTime) {
      return value;
    }

    return DateTime.tryParse(value.toString()) ?? DateTime.now();
  }
}
