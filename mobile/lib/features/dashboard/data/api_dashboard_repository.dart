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
        final json = data as Map<String, dynamic>;
        final overviewJson = json['today_overview'] as Map<String, dynamic>;
        return DashboardData(
          overview: TodayOverview(
            totalTasks: overviewJson['total_tasks'] as int? ?? 0,
            completed: overviewJson['completed'] as int? ?? 0,
            pending: overviewJson['pending'] as int? ?? 0,
            overdue: overviewJson['overdue'] as int? ?? 0,
          ),
          priorityTask: json['priority_task'] == null ? null : StaffTask.fromJson(json['priority_task'] as Map<String, dynamic>),
          recentTasks: ((json['recent_tasks'] as List<dynamic>?) ?? [])
              .map((e) => StaffTask.fromJson(e as Map<String, dynamic>))
              .toList(),
          announcements: ((json['announcements'] as List<dynamic>?) ?? [])
              .map((e) => Announcement(
                    id: e['id'] as String,
                    title: e['title'] as String,
                    body: e['body'] as String,
                    postedAt: DateTime.parse(e['posted_at'] as String),
                  ))
              .toList(),
        );
      },
    );
  }
}
