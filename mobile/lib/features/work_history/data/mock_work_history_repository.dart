import '../domain/work_history_models.dart';
import 'work_history_repository.dart';

class MockWorkHistoryRepository implements WorkHistoryRepository {
  @override
  Future<List<WorkOrderHistoryItem>> fetchHistory() async {
    await Future.delayed(const Duration(milliseconds: 400));
    return [
      WorkOrderHistoryItem.fromJson({
        'id': 501,
        'reference': 'WO-2026-0071',
        'title': 'Replace corridor light fitting',
        'location': 'Block A – Level 3',
        'priority': 'normal',
        'status': 'Completed',
        'verification_status': 'verified',
        'completed_at':
            DateTime.now().subtract(const Duration(days: 2)).toIso8601String(),
        'verified_by': 'Property Admin',
        'verified_at':
            DateTime.now().subtract(const Duration(days: 1)).toIso8601String(),
        'daily_work_entries': [
          {
            'id': 1,
            'reference': 'DW-20260815-AB12',
            'date': DateTime.now()
                .subtract(const Duration(days: 2))
                .toIso8601String()
                .split('T')
                .first,
            'description': 'Replaced fluorescent tube and cleaned fitting.',
            'status': 'Verified',
            'verified_by': 'Property Admin',
          },
        ],
        'images': [
          {
            'type': 'Before',
            'url': 'https://picsum.photos/seed/wo1before/400/400'
          },
          {
            'type': 'After',
            'url': 'https://picsum.photos/seed/wo1after/400/400'
          },
        ],
      }),
      WorkOrderHistoryItem.fromJson({
        'id': 502,
        'reference': 'WO-2026-0074',
        'title': 'Fix leaking pipe at Guard House',
        'location': 'Guard House',
        'priority': 'high',
        'status': 'Completed',
        'verification_status': 'rejected',
        'completed_at':
            DateTime.now().subtract(const Duration(days: 4)).toIso8601String(),
        'rejection_reason':
            'Photo does not clearly show the repaired joint. Please resubmit with a closer photo.',
        'daily_work_entries': [
          {
            'id': 2,
            'reference': 'DW-20260813-CD34',
            'date': DateTime.now()
                .subtract(const Duration(days: 4))
                .toIso8601String()
                .split('T')
                .first,
            'description': 'Sealed leaking joint with new fitting.',
            'status': 'Rejected',
            'supervisor_remarks':
                'Photo does not clearly show the repaired joint. Please resubmit with a closer photo.',
          },
        ],
        'images': [
          {
            'type': 'Before',
            'url': 'https://picsum.photos/seed/wo2before/400/400'
          },
          {
            'type': 'After',
            'url': 'https://picsum.photos/seed/wo2after/400/400'
          },
        ],
      }),
    ];
  }
}
