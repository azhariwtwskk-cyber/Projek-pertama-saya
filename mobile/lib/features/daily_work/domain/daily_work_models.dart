enum DailyWorkCategory { cleaning, maintenance, electrical, plumbing, landscaping, generalWork, inspectionSupport, other }

extension DailyWorkCategoryLabel on DailyWorkCategory {
  String get label {
    switch (this) {
      case DailyWorkCategory.cleaning:
        return 'Cleaning';
      case DailyWorkCategory.maintenance:
        return 'Maintenance';
      case DailyWorkCategory.electrical:
        return 'Electrical';
      case DailyWorkCategory.plumbing:
        return 'Plumbing';
      case DailyWorkCategory.landscaping:
        return 'Landscaping';
      case DailyWorkCategory.generalWork:
        return 'General Work';
      case DailyWorkCategory.inspectionSupport:
        return 'Inspection Support';
      case DailyWorkCategory.other:
        return 'Other';
    }
  }
}

enum DailyWorkStatus { draft, submitted, verified }

class DailyWorkEntry {
  const DailyWorkEntry({
    required this.id,
    required this.title,
    required this.category,
    required this.location,
    required this.description,
    required this.startTime,
    this.completionTime,
    this.photoCount = 0,
    this.remarks,
    this.status = DailyWorkStatus.submitted,
  });

  final String id;
  final String title;
  final DailyWorkCategory category;
  final String location;
  final String description;
  final DateTime startTime;
  final DateTime? completionTime;
  final int photoCount;
  final String? remarks;
  final DailyWorkStatus status;

  bool get isReadOnly => status == DailyWorkStatus.verified;

  Duration? get duration => completionTime?.difference(startTime);
}
