enum NotificationType {
  newWorkOrder,
  newAssignment,
  urgentTask,
  inspectionCorrectiveAction,
  pmDue,
  pmOverdue,
  taskRejected,
  taskVerified,
  attendanceReminder,
  managementAnnouncement,
}

extension NotificationTypeMeta on NotificationType {
  String get label {
    switch (this) {
      case NotificationType.newWorkOrder:
        return 'New Work Order';
      case NotificationType.newAssignment:
        return 'New Assignment';
      case NotificationType.urgentTask:
        return 'Urgent Task';
      case NotificationType.inspectionCorrectiveAction:
        return 'Corrective Action';
      case NotificationType.pmDue:
        return 'PM Due';
      case NotificationType.pmOverdue:
        return 'PM Overdue';
      case NotificationType.taskRejected:
        return 'Task Requires Attention';
      case NotificationType.taskVerified:
        return 'Task Verified';
      case NotificationType.attendanceReminder:
        return 'Attendance Reminder';
      case NotificationType.managementAnnouncement:
        return 'Announcement';
    }
  }

  bool get isUrgent =>
      this == NotificationType.urgentTask ||
      this == NotificationType.pmOverdue ||
      this == NotificationType.taskRejected;
}

class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.createdAt,
    this.deepLinkRoute,
    this.isRead = false,
  });

  final String id;
  final NotificationType type;
  final String title;
  final String body;
  final DateTime createdAt;
  final String? deepLinkRoute;
  final bool isRead;

  AppNotification copyWith({bool? isRead}) => AppNotification(
        id: id,
        type: type,
        title: title,
        body: body,
        createdAt: createdAt,
        deepLinkRoute: deepLinkRoute,
        isRead: isRead ?? this.isRead,
      );
}
