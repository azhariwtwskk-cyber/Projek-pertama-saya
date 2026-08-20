enum PmStatus { today, upcoming, overdue, completed }

class PmChecklistItem {
  const PmChecklistItem(
      {required this.id,
      required this.label,
      this.isMandatory = true,
      this.isChecked = false});

  final String id;
  final String label;
  final bool isMandatory;
  final bool isChecked;

  PmChecklistItem copyWith({bool? isChecked}) => PmChecklistItem(
      id: id,
      label: label,
      isMandatory: isMandatory,
      isChecked: isChecked ?? this.isChecked);
}

class PmTask {
  const PmTask({
    required this.id,
    required this.assetName,
    required this.assetId,
    required this.location,
    required this.pmType,
    required this.scheduledDate,
    required this.instructions,
    required this.status,
    required this.checklist,
    this.requiresPhotoEvidence = true,
  });

  final String id;
  final String assetName;
  final String assetId;
  final String location;
  final String pmType;
  final DateTime scheduledDate;
  final String instructions;
  final PmStatus status;
  final List<PmChecklistItem> checklist;
  final bool requiresPhotoEvidence;

  bool get allMandatoryChecked =>
      checklist.where((c) => c.isMandatory).every((c) => c.isChecked);
}
