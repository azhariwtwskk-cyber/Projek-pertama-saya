import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/dashboard_models.dart';
import 'dashboard_repository.dart';

class MockDashboardRepository implements DashboardRepository {
  @override
  Future<DashboardData> fetchDashboard() async {
    await Future.delayed(const Duration(milliseconds: 500));
    return MockFixtures.instance.buildDashboard();
  }
}
