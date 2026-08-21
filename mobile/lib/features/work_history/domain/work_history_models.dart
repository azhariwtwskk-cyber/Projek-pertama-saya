import '../../../core/config/app_config.dart';

/// The management verification decision for a completed work order.
/// Reconciled server-side (see `cpms/api/v1/staff/work-history.php`) from
/// `daily_work_logs.work_status`/`supervisor_remarks` — the Property
/// Admin's Daily Work Review page (`cpms/property_portal/
/// daily_work_review.php`) is what actually sets these, never
/// `work_orders.status` directly.
enum WorkOrderVerificationStatus {
  inProgress,
  pendingVerification,
  verified,
  rejected
}

WorkOrderVerificationStatus _verificationFromString(String value) {
  switch (value) {
    case 'pending_verification':
      return WorkOrderVerificationStatus.pendingVerification;
    case 'verified':
      return WorkOrderVerificationStatus.verified;
    case 'rejected':
      return WorkOrderVerificationStatus.rejected;
    default:
      return WorkOrderVerificationStatus.inProgress;
  }
}

class WorkHistoryPhoto {
  const WorkHistoryPhoto({required this.type, required this.url});
  final String type;
  final String url;

  factory WorkHistoryPhoto.fromJson(Map<String, dynamic> json) =>
      WorkHistoryPhoto(
        type: (json['type'] ?? 'Supporting').toString(),
        url: AppConfig.resolveUrl((json['url'] ?? '').toString()),
      );
}

class DailyWorkHistoryEntry {
  const DailyWorkHistoryEntry({
    required this.id,
    required this.reference,
    required this.date,
    required this.description,
    required this.status,
    this.verifiedBy,
    this.verifiedAt,
    this.supervisorRemarks,
  });

  final int id;
  final String reference;
  final String date;
  final String description;
  final String status;
  final String? verifiedBy;
  final DateTime? verifiedAt;
  final String? supervisorRemarks;

  factory DailyWorkHistoryEntry.fromJson(Map<String, dynamic> json) =>
      DailyWorkHistoryEntry(
        id: (json['id'] as num?)?.toInt() ?? 0,
        reference: (json['reference'] ?? '').toString(),
        date: (json['date'] ?? '').toString(),
        description: (json['description'] ?? '').toString(),
        status: (json['status'] ?? '').toString(),
        verifiedBy: json['verified_by'] as String?,
        verifiedAt: json['verified_at'] == null
            ? null
            : DateTime.tryParse(json['verified_at'].toString()),
        supervisorRemarks:
            (json['supervisor_remarks'] as String?)?.trim().isEmpty == true
                ? null
                : json['supervisor_remarks'] as String?,
      );
}

class WorkOrderHistoryItem {
  const WorkOrderHistoryItem({
    required this.id,
    required this.reference,
    required this.title,
    required this.location,
    required this.priority,
    required this.status,
    required this.verificationStatus,
    this.completionNotes,
    this.completedAt,
    this.verifiedBy,
    this.verifiedAt,
    this.rejectionReason,
    this.dailyWorkEntries = const [],
    this.photos = const [],
  });

  final int id;
  final String reference;
  final String title;
  final String location;
  final String priority;
  final String status;
  final WorkOrderVerificationStatus verificationStatus;
  final String? completionNotes;
  final DateTime? completedAt;
  final String? verifiedBy;
  final DateTime? verifiedAt;
  final String? rejectionReason;
  final List<DailyWorkHistoryEntry> dailyWorkEntries;
  final List<WorkHistoryPhoto> photos;

  List<WorkHistoryPhoto> photosOfType(String type) =>
      photos.where((p) => p.type == type).toList();

  factory WorkOrderHistoryItem.fromJson(Map<String, dynamic> json) {
    String str(dynamic v, [String fallback = '']) =>
        v == null ? fallback : v.toString();
    final rejectionReason = (json['rejection_reason'] as String?)?.trim();
    return WorkOrderHistoryItem(
      id: (json['id'] as num?)?.toInt() ?? 0,
      reference: str(json['reference']),
      title: str(json['title'], 'Work Order'),
      location: str(json['location']),
      priority: str(json['priority']),
      status: str(json['status']),
      verificationStatus:
          _verificationFromString(str(json['verification_status'])),
      completionNotes:
          (json['completion_notes'] as String?)?.trim().isEmpty == true
              ? null
              : json['completion_notes'] as String?,
      completedAt: json['completed_at'] == null
          ? null
          : DateTime.tryParse(json['completed_at'].toString()),
      verifiedBy: json['verified_by'] as String?,
      verifiedAt: json['verified_at'] == null
          ? null
          : DateTime.tryParse(json['verified_at'].toString()),
      rejectionReason: (rejectionReason == null || rejectionReason.isEmpty)
          ? null
          : rejectionReason,
      dailyWorkEntries: ((json['daily_work_entries'] as List<dynamic>?) ?? [])
          .whereType<Map>()
          .map((e) =>
              DailyWorkHistoryEntry.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      photos: ((json['images'] as List<dynamic>?) ?? [])
          .whereType<Map>()
          .map((e) => WorkHistoryPhoto.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
    );
  }
}
