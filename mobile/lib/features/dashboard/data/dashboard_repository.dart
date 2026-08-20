import '../domain/dashboard_models.dart';

abstract class DashboardRepository {
  Future<DashboardData> fetchDashboard();
}
