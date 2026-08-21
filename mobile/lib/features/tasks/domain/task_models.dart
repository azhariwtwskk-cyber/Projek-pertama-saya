import '../../../core/config/app_config.dart';

enum TaskPriority { low, normal, high, urgent }

enum TaskStatus {
  newTask,
  accepted,
  inProgress,
  workCompleted,
  pendingVerification,
  verified,
  rejected,
  overdue,
}

enum TaskCategory {
  workOrder,
  inspectionCorrectiveAction,
  preventiveMaintenance,
  dailyAssignment,
  supervisorTask
}

TaskPriority taskPriorityFromString(String value) {
  switch (value.toLowerCase()) {
    case 'urgent':
      return TaskPriority.urgent;
    case 'high':
      return TaskPriority.high;
    case 'low':
      return TaskPriority.low;
    default:
      return TaskPriority.normal;
  }
}

/// The real backend (`cpms/api/v1/staff/tasks.php`) only ever returns one
/// of three collapsed buckets — `pending`, `in_progress`, `completed`
/// (see `cpmsApiTaskStatus()` server-side) — there is no accept/start/
/// work-completed/pending-verification/rejected state for work orders.
/// `completed` covers both `Completed` and `Verified` work orders, so it
/// maps to the terminal [TaskStatus.verified] here (nothing more for
/// staff to do either way). The other enum values remain for demo/mock
/// data and other task categories, they just never come from a real
/// work-order response today.
TaskStatus taskStatusFromString(String value) {
  switch (value.toLowerCase()) {
    case 'new':
    case 'pending':
      return TaskStatus.newTask;
    case 'accepted':
      return TaskStatus.accepted;
    case 'in_progress':
      return TaskStatus.inProgress;
    case 'work_completed':
      return TaskStatus.workCompleted;
    case 'pending_verification':
      return TaskStatus.pendingVerification;
    case 'completed':
    case 'verified':
      return TaskStatus.verified;
    case 'rejected':
      return TaskStatus.rejected;
    case 'overdue':
      return TaskStatus.overdue;
    default:
      return TaskStatus.newTask;
  }
}

TaskCategory taskCategoryFromString(String value) {
  switch (value.toLowerCase()) {
    case 'inspection_corrective_action':
      return TaskCategory.inspectionCorrectiveAction;
    case 'preventive_maintenance':
      return TaskCategory.preventiveMaintenance;
    case 'daily_assignment':
      return TaskCategory.dailyAssignment;
    case 'supervisor_task':
      return TaskCategory.supervisorTask;
    default:
      return TaskCategory.workOrder;
  }
}

extension TaskCategoryLabel on TaskCategory {
  String get label {
    switch (this) {
      case TaskCategory.workOrder:
        return 'Work Order';
      case TaskCategory.inspectionCorrectiveAction:
        return 'Corrective Action';
      case TaskCategory.preventiveMaintenance:
        return 'Preventive Maintenance';
      case TaskCategory.dailyAssignment:
        return 'Daily Assignment';
      case TaskCategory.supervisorTask:
        return 'Supervisor Task';
    }
  }
}

class EvidencePhoto {
  const EvidencePhoto({
    required this.id,
    required this.url,
    required this.uploadedAt,
    this.uploadedByRole,
    this.gpsLat,
    this.gpsLng,
    this.isLocalPending = false,
    this.localPath,
  });

  final String id;
  final String url;
  final DateTime uploadedAt;
  final String? uploadedByRole;
  final double? gpsLat;
  final double? gpsLng;
  final bool isLocalPending;
  final String? localPath;

  String get displaySource => isLocalPending ? (localPath ?? url) : url;

  factory EvidencePhoto.fromJson(Map<String, dynamic> json) => EvidencePhoto(
        id: (json['id'] ?? '').toString(),
        url: AppConfig.resolveUrl((json['url'] ?? '').toString()),
        uploadedAt: DateTime.tryParse((json['uploaded_at'] ?? '').toString()) ??
            DateTime.now(),
        uploadedByRole: json['uploaded_by_role'] as String?,
        gpsLat: (json['gps_lat'] as num?)?.toDouble(),
        gpsLng: (json['gps_lng'] as num?)?.toDouble(),
      );

  /// The real `staff/task-photo.php` upload response is
  /// `{"uploaded":true,"image_id":123,"work_order_reference":"...",
  /// "image_url":"/cpms/uploads/..."}` — a different shape from
  /// [fromJson] above (no `uploaded_at`, `id` is `image_id`, `url` is
  /// `image_url`), so it gets its own factory rather than forcing that
  /// parser to guess between two contracts.
  factory EvidencePhoto.fromUploadResponse(Map<String, dynamic> json) =>
      EvidencePhoto(
        id: (json['image_id'] ?? '').toString(),
        url: AppConfig.resolveUrl((json['image_url'] ?? '').toString()),
        uploadedAt: DateTime.now(),
      );
}

class InspectionIssue {
  const InspectionIssue({
    required this.issue,
    required this.location,
    required this.severity,
    required this.inspectorRemark,
    required this.beforePhotos,
  });

  final String issue;
  final String location;
  final String severity;
  final String inspectorRemark;
  final List<EvidencePhoto> beforePhotos;
}

class StaffTask {
  const StaffTask({
    required this.id,
    this.databaseId,
    required this.taskNumber,
    required this.title,
    required this.description,
    required this.category,
    required this.priority,
    required this.status,
    required this.propertyName,
    required this.location,
    required this.assignedBy,
    required this.assignedDate,
    required this.dueDate,
    this.inspectionIssue,
    this.afterPhotos = const [],
    this.existingImageUrl,
    this.completionRemarks,
    this.materialsUsed,
    this.timeSpentMinutes,
    this.rejectionReason,
    this.requiresEvidence = true,
  });

  /// The work order *reference* (e.g. `WO-2026-0082`) — this is what the
  /// real backend calls `id` in list responses and is also what
  /// `staff/task-photo.php` expects as `work_order_reference`.
  final String id;

  /// The real numeric `work_orders.id` (`database_id` in the list
  /// response) — only this value, never [id], is accepted by
  /// `staff/daily-work/submit.php`'s `work_order_id` field. Null for
  /// demo/mock tasks that were never round-tripped through the API.
  final int? databaseId;
  final String taskNumber;
  final String title;
  final String description;
  final TaskCategory category;
  final TaskPriority priority;
  final TaskStatus status;
  final String propertyName;
  final String location;
  final String assignedBy;
  final DateTime assignedDate;
  final DateTime dueDate;
  final InspectionIssue? inspectionIssue;
  final List<EvidencePhoto> afterPhotos;

  /// The single latest work-order photo thumbnail the list endpoint
  /// already includes (`image_url`) — the backend has no endpoint that
  /// returns the *full* evidence gallery for a work order, so this is
  /// the only pre-existing evidence reference the app can show before
  /// any new photos are captured this session.
  final String? existingImageUrl;
  final String? completionRemarks;
  final String? materialsUsed;
  final int? timeSpentMinutes;
  final String? rejectionReason;
  final bool requiresEvidence;

  bool get isOverdue =>
      status != TaskStatus.verified && DateTime.now().isAfter(dueDate);

  /// Shared parser for every endpoint that returns this task shape (list,
  /// detail, accept, start, complete, and the dashboard's priority/recent
  /// tasks) so the mapping lives in exactly one place.
  factory StaffTask.fromJson(Map<String, dynamic> json) {
    String str(dynamic v, [String fallback = '']) =>
        v == null ? fallback : v.toString();
    DateTime date(dynamic v, {DateTime? fallback}) {
      final raw = str(v).trim();
      if (raw.isEmpty || raw == '-') return fallback ?? DateTime.now();
      final iso = DateTime.tryParse(raw);
      if (iso != null) return iso;
      final parts = raw.split('/');
      if (parts.length == 3) {
        final d = int.tryParse(parts[0]);
        final m = int.tryParse(parts[1]);
        final y = int.tryParse(parts[2]);
        if (d != null && m != null && y != null) return DateTime(y, m, d);
      }
      return fallback ?? DateTime.now();
    }

    final id = str(json['id'] ?? json['database_id']);
    final taskNo =
        str(json['task_number'] ?? json['id'] ?? json['reference'], id);
    final assigned = date(json['assigned_date'] ?? json['created_at']);
    final due = date(json['due_date'] ?? json['due'],
        fallback: assigned.add(const Duration(days: 7)));
    final rawImageUrl = str(json['image_url']).trim();
    return StaffTask(
      id: id,
      databaseId: json['database_id'] is int
          ? json['database_id'] as int
          : int.tryParse(str(json['database_id'])),
      taskNumber: taskNo,
      title: str(json['title'], 'Task'),
      description: str(json['description']),
      category:
          taskCategoryFromString(json['category'] as String? ?? 'work_order'),
      priority: taskPriorityFromString(json['priority'] as String? ?? 'normal'),
      status: taskStatusFromString(json['status'] as String? ?? 'new'),
      propertyName: json['property_name'] as String? ?? '',
      location: json['location'] as String? ?? '',
      assignedBy: json['assigned_by'] as String? ?? '',
      assignedDate: assigned,
      dueDate: due,
      completionRemarks: json['completion_remarks'] as String?,
      materialsUsed: json['materials_used'] as String?,
      timeSpentMinutes: json['time_spent_minutes'] as int?,
      rejectionReason: json['rejection_reason'] as String?,
      requiresEvidence: json['requires_evidence'] as bool? ?? true,
      afterPhotos: ((json['after_photos'] as List<dynamic>?) ?? [])
          .map((e) => EvidencePhoto.fromJson(e as Map<String, dynamic>))
          .toList(),
      existingImageUrl:
          rawImageUrl.isEmpty ? null : AppConfig.resolveUrl(rawImageUrl),
      inspectionIssue: json['inspection_issue'] == null
          ? null
          : InspectionIssue(
              issue: json['inspection_issue']['issue'] as String? ?? '',
              location: json['inspection_issue']['location'] as String? ?? '',
              severity: json['inspection_issue']['severity'] as String? ?? '',
              inspectorRemark:
                  json['inspection_issue']['inspector_remark'] as String? ?? '',
              beforePhotos: ((json['inspection_issue']['before_photos']
                          as List<dynamic>?) ??
                      [])
                  .map((e) => EvidencePhoto.fromJson(e as Map<String, dynamic>))
                  .toList(),
            ),
    );
  }

  StaffTask copyWith({
    TaskStatus? status,
    List<EvidencePhoto>? afterPhotos,
    String? completionRemarks,
    String? materialsUsed,
    int? timeSpentMinutes,
    String? rejectionReason,
  }) {
    return StaffTask(
      id: id,
      databaseId: databaseId,
      taskNumber: taskNumber,
      title: title,
      description: description,
      category: category,
      priority: priority,
      status: status ?? this.status,
      propertyName: propertyName,
      location: location,
      assignedBy: assignedBy,
      assignedDate: assignedDate,
      dueDate: dueDate,
      inspectionIssue: inspectionIssue,
      afterPhotos: afterPhotos ?? this.afterPhotos,
      existingImageUrl: existingImageUrl,
      completionRemarks: completionRemarks ?? this.completionRemarks,
      materialsUsed: materialsUsed ?? this.materialsUsed,
      timeSpentMinutes: timeSpentMinutes ?? this.timeSpentMinutes,
      rejectionReason: rejectionReason,
      requiresEvidence: requiresEvidence,
    );
  }
}
