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

enum TaskCategory { workOrder, inspectionCorrectiveAction, preventiveMaintenance, dailyAssignment, supervisorTask }

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

TaskStatus taskStatusFromString(String value) {
  switch (value.toLowerCase()) {
    case 'new':
      return TaskStatus.newTask;
    case 'accepted':
      return TaskStatus.accepted;
    case 'in_progress':
      return TaskStatus.inProgress;
    case 'work_completed':
      return TaskStatus.workCompleted;
    case 'pending_verification':
      return TaskStatus.pendingVerification;
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
        id: json['id'] as String,
        url: json['url'] as String,
        uploadedAt: DateTime.parse(json['uploaded_at'] as String),
        uploadedByRole: json['uploaded_by_role'] as String?,
        gpsLat: (json['gps_lat'] as num?)?.toDouble(),
        gpsLng: (json['gps_lng'] as num?)?.toDouble(),
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
    this.completionRemarks,
    this.materialsUsed,
    this.timeSpentMinutes,
    this.rejectionReason,
    this.requiresEvidence = true,
  });

  final String id;
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
  final String? completionRemarks;
  final String? materialsUsed;
  final int? timeSpentMinutes;
  final String? rejectionReason;
  final bool requiresEvidence;

  bool get isOverdue => status != TaskStatus.verified && DateTime.now().isAfter(dueDate);

  /// Shared parser for every endpoint that returns this task shape (list,
  /// detail, accept, start, complete, and the dashboard's priority/recent
  /// tasks) so the mapping lives in exactly one place.
  factory StaffTask.fromJson(Map<String, dynamic> json) {
    return StaffTask(
      id: json['id'] as String,
      taskNumber: json['task_number'] as String,
      title: json['title'] as String,
      description: json['description'] as String? ?? '',
      category: taskCategoryFromString(json['category'] as String? ?? 'work_order'),
      priority: taskPriorityFromString(json['priority'] as String? ?? 'normal'),
      status: taskStatusFromString(json['status'] as String? ?? 'new'),
      propertyName: json['property_name'] as String? ?? '',
      location: json['location'] as String? ?? '',
      assignedBy: json['assigned_by'] as String? ?? '',
      assignedDate: DateTime.parse(json['assigned_date'] as String),
      dueDate: DateTime.parse(json['due_date'] as String),
      completionRemarks: json['completion_remarks'] as String?,
      materialsUsed: json['materials_used'] as String?,
      timeSpentMinutes: json['time_spent_minutes'] as int?,
      rejectionReason: json['rejection_reason'] as String?,
      requiresEvidence: json['requires_evidence'] as bool? ?? true,
      afterPhotos: ((json['after_photos'] as List<dynamic>?) ?? [])
          .map((e) => EvidencePhoto.fromJson(e as Map<String, dynamic>))
          .toList(),
      inspectionIssue: json['inspection_issue'] == null
          ? null
          : InspectionIssue(
              issue: json['inspection_issue']['issue'] as String? ?? '',
              location: json['inspection_issue']['location'] as String? ?? '',
              severity: json['inspection_issue']['severity'] as String? ?? '',
              inspectorRemark: json['inspection_issue']['inspector_remark'] as String? ?? '',
              beforePhotos: ((json['inspection_issue']['before_photos'] as List<dynamic>?) ?? [])
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
      completionRemarks: completionRemarks ?? this.completionRemarks,
      materialsUsed: materialsUsed ?? this.materialsUsed,
      timeSpentMinutes: timeSpentMinutes ?? this.timeSpentMinutes,
      rejectionReason: rejectionReason,
      requiresEvidence: requiresEvidence,
    );
  }
}
