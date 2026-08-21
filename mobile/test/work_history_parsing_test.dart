import 'package:flutter_test/flutter_test.dart';

import 'package:cpmspro_workforce/features/work_history/domain/work_history_models.dart';

/// Regression coverage for the real-device "Before/After images not
/// showing" and "task disappears" fixes: `WorkOrderHistoryItem.fromJson`
/// must parse the shapes `cpms/api/v1/staff/work-history.php` actually
/// returns (see AUDIT_REPORT.md / API_CONTRACT.md), including the four
/// verification-status buckets the Property Admin's Daily Work Review
/// decision reconciles into.
void main() {
  group('WorkOrderHistoryItem.fromJson — image parsing', () {
    test('parses images[] into typed, resolvable photo URLs', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 42,
        'reference': 'WO-2026-0100',
        'title': 'Repair leaking pipe',
        'location': 'Block B - Level 3',
        'priority': 'High',
        'status': 'Completed',
        'verification_status': 'pending_verification',
        'images': [
          {'type': 'Before', 'url': '/cpms/uploads/daily_work/before.jpg'},
          {'type': 'After', 'url': '/cpms/uploads/daily_work/after.jpg'},
          {
            'type': 'Supporting',
            'url': '/uploads/daily_work/property_5/extra.jpg',
          },
        ],
      });

      expect(item.photos, hasLength(3));
      expect(item.photosOfType('Before'), hasLength(1));
      expect(item.photosOfType('After'), hasLength(1));
      expect(item.photosOfType('Supporting'), hasLength(1));
      // Root-relative backend paths must be resolved to absolute URLs
      // before ever reaching an Image.network widget — this is the exact
      // contract AppConfig.resolveUrl exists to satisfy.
      for (final photo in item.photos) {
        expect(photo.url, startsWith('http'));
      }
      expect(item.photosOfType('Before').single.url,
          endsWith('/cpms/uploads/daily_work/before.jpg'));
      // A photo resolved via the legacy web portal's property-subfolder
      // convention (no "cpms/" prefix) must resolve too, not just the
      // mobile app's own flat convention.
      expect(item.photosOfType('Supporting').single.url,
          endsWith('/uploads/daily_work/property_5/extra.jpg'));
    });

    test('an empty images[] list yields no photos, not a crash', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'Completed',
        'verification_status': 'pending_verification',
        'images': <dynamic>[],
      });
      expect(item.photos, isEmpty);
    });

    test('a missing images key yields no photos, not a crash', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'Completed',
        'verification_status': 'pending_verification',
      });
      expect(item.photos, isEmpty);
    });
  });

  group('WorkOrderHistoryItem.fromJson — verification status mapping', () {
    test(
        'a Completed work order with no decision yet maps to '
        'pendingVerification', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'Completed',
        'verification_status': 'pending_verification',
      });
      expect(item.verificationStatus,
          WorkOrderVerificationStatus.pendingVerification);
    });

    test('a Verified decision maps to verified with verifier metadata', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'Completed',
        'verification_status': 'verified',
        'verified_by': 'Jane Property Admin',
        'verified_at': '2026-08-19T10:00:00Z',
      });
      expect(item.verificationStatus, WorkOrderVerificationStatus.verified);
      expect(item.verifiedBy, 'Jane Property Admin');
      expect(item.verifiedAt, isNotNull);
      expect(item.rejectionReason, isNull);
    });

    test('a Rejected decision maps to rejected with a rejection reason', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'Completed',
        'verification_status': 'rejected',
        'rejection_reason': 'After photo does not show the repair clearly.',
      });
      expect(item.verificationStatus, WorkOrderVerificationStatus.rejected);
      expect(item.rejectionReason,
          'After photo does not show the repair clearly.');
    });

    test(
        'an unrecognized/blank verification_status defaults to '
        'inProgress rather than throwing', () {
      final item = WorkOrderHistoryItem.fromJson({
        'id': 1,
        'reference': 'WO-1',
        'title': 'Task',
        'location': 'A',
        'priority': 'Low',
        'status': 'In Progress',
        'verification_status': '',
      });
      expect(item.verificationStatus, WorkOrderVerificationStatus.inProgress);
    });
  });
}
