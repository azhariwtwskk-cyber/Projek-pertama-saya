class PropertyAsset {
  const PropertyAsset({
    required this.id,
    required this.name,
    required this.propertyName,
    required this.location,
    required this.status,
    required this.lastMaintenanceDate,
    required this.nextMaintenanceDate,
  });

  final String id;
  final String name;
  final String propertyName;
  final String location;
  final String status;
  final DateTime? lastMaintenanceDate;
  final DateTime? nextMaintenanceDate;
}
