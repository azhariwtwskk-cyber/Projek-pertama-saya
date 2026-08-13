import '../../tasks/domain/task_models.dart';

class TodayOverview {
  const TodayOverview({
    required this.totalTasks,
    required this.completed,
    required this.pending,
    required this.overdue,
  });

  final int totalTasks;
  final int completed;
  final int pending;
  final int overdue;
}

class Announcement {
  const Announcement({required this.id, required this.title, required this.body, required this.postedAt});
  final String id;
  final String title;
  final String body;
  final DateTime postedAt;
}

class DashboardData {
  const DashboardData({
    required this.overview,
    required this.priorityTask,
    required this.recentTasks,
    required this.announcements,
  });

  final TodayOverview overview;
  final StaffTask? priorityTask;
  final List<StaffTask> recentTasks;
  final List<Announcement> announcements;
}
