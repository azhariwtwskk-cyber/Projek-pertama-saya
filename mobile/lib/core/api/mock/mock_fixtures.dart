import 'dart:math';

import 'package:flutter/material.dart' show Color;

import '../../../features/assets/domain/asset_models.dart';
import '../../../features/attendance/domain/attendance_models.dart';
import '../../../features/auth/domain/staff_user.dart';
import '../../../features/dashboard/domain/dashboard_models.dart';
import '../../../features/daily_work/domain/daily_work_models.dart';
import '../../../features/notifications/domain/notification_models.dart';
import '../../../features/preventive_maintenance/domain/pm_models.dart';
import '../../../features/tasks/domain/task_models.dart';
import '../../config/app_branding.dart';
import '../../permissions/staff_permissions.dart';

/// One consistent in-memory "backend" shared by every mock repository, so
/// the same staff member, property and task IDs show up everywhere in the
/// app (dashboard, tasks, notifications, PM) exactly as a real CPMSPro
/// deployment would return them. This is intentionally the ONLY place that
/// invents demo data — repositories never fabricate their own.
class MockFixtures {
  MockFixtures._();
  static final MockFixtures instance = MockFixtures._();

  final AppBranding branding = const AppBranding(
    cpmsproLogoUrl: '',
    propertyLogoUrl: '',
    propertyName: 'V23 Malawa Ria Apartment',
    managementCompanyName: 'Malawa Property Management Sdn Bhd',
    primaryColor: Color(0xFF0B5FFF),
    secondaryColor: Color(0xFF12B76A),
  );

  // `late`: these read the `instance` static field via `instance.branding`,
  // so they must not be evaluated as part of building `instance` itself
  // (eager initializers run before the `static final instance = ...`
  // assignment completes, which previously caused unbounded recursion).
  late final StaffUser staffUser = StaffUser(
    userId: 'usr_1029',
    staffId: 'STF-2031',
    propertyId: 'PROP-V23',
    name: 'Ahmad Rozahari',
    role: 'Maintenance Technician',
    username: 'ahmad.rozahari',
    employeeId: 'EMP-04471',
    phone: '+60 12-345 6789',
    email: 'rozahari88@gmail.com',
    profileImageUrl: '',
    permissions: StaffPermissions.fromList(const [
      'staff.dashboard.view',
      'staff.workorder.view',
      'staff.workorder.update',
      'staff.dailywork.create',
      'staff.pm.view',
      'staff.pm.complete',
      'staff.attendance.clock',
      'staff.asset.view',
      'staff.notification.view',
    ]),
    branding: instance.branding,
  );

  late final List<StaffTask> tasks = _buildTasks();
  late final List<PmTask> pmTasks = _buildPmTasks();
  final List<DailyWorkEntry> dailyWork = [];
  late final List<AppNotification> notifications = _buildNotifications();
  late final List<PropertyAsset> assets = _buildAssets();
  late final List<Announcement> announcements = [
    Announcement(
      id: 'ANN-1',
      title: 'Water supply maintenance this weekend',
      body: 'Main water supply will be interrupted Saturday 9AM-12PM for tank cleaning.',
      postedAt: DateTime.now().subtract(const Duration(hours: 5)),
    ),
  ];

  late AttendanceStatus attendanceStatus = AttendanceStatus(
    status: ClockStatus.clockedOut,
    propertyName: instance.branding.propertyName,
  );

  final List<AttendanceRecord> attendanceHistory = _buildAttendanceHistory();

  DashboardData buildDashboard() {
    final active = tasks.where((t) => t.status != TaskStatus.verified).toList();
    final completed = tasks.where((t) => t.status == TaskStatus.verified || t.status == TaskStatus.pendingVerification).length;
    final overdue = tasks.where((t) => t.isOverdue).length;
    final priority = active.where((t) => t.priority == TaskPriority.urgent || t.priority == TaskPriority.high).isNotEmpty
        ? active.firstWhere((t) => t.priority == TaskPriority.urgent, orElse: () => active.firstWhere((t) => t.priority == TaskPriority.high))
        : (active.isNotEmpty ? active.first : null);

    return DashboardData(
      overview: TodayOverview(
        totalTasks: tasks.length,
        completed: completed,
        pending: tasks.length - completed - overdue,
        overdue: overdue,
      ),
      priorityTask: priority,
      recentTasks: tasks.take(4).toList(),
      announcements: announcements,
    );
  }

  static List<StaffTask> _buildTasks() {
    final now = DateTime.now();
    return [
      StaffTask(
        id: 'WO-2026-0082',
        taskNumber: 'WO-2026-0082',
        title: 'Corridor Light Not Working',
        description: 'Resident reported flickering and non-functional corridor light near unit B-02-05.',
        category: TaskCategory.workOrder,
        priority: TaskPriority.high,
        status: TaskStatus.newTask,
        propertyName: instance.branding.propertyName,
        location: 'Block B — Level 2',
        assignedBy: 'Property Admin — Siti Aminah',
        assignedDate: now.subtract(const Duration(hours: 3)),
        dueDate: DateTime(now.year, now.month, now.day, 17, 0),
      ),
      StaffTask(
        id: 'ICA-2026-0341',
        taskNumber: 'ICA-2026-0341',
        title: 'Dirty Staircase Requires Cleaning',
        description: 'Weekly inspection flagged staircase cleanliness below standard.',
        category: TaskCategory.inspectionCorrectiveAction,
        priority: TaskPriority.normal,
        status: TaskStatus.accepted,
        propertyName: instance.branding.propertyName,
        location: 'Block C — Level 3',
        assignedBy: 'Inspector — Kumar Raj',
        assignedDate: now.subtract(const Duration(days: 1)),
        dueDate: now.add(const Duration(hours: 6)),
        inspectionIssue: InspectionIssue(
          issue: 'Dirty staircase',
          location: 'Block C — Level 3',
          severity: 'Medium',
          inspectorRemark: 'Staircase requires cleaning, especially near the fire door.',
          beforePhotos: [
            EvidencePhoto(
              id: 'before_1',
              url: 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=800',
              uploadedAt: now.subtract(const Duration(days: 1)),
              uploadedByRole: 'Inspector',
            ),
          ],
        ),
      ),
      StaffTask(
        id: 'PM-2026-0119',
        taskNumber: 'PM-2026-0119',
        title: 'Water Pump Inspection',
        description: 'Scheduled monthly preventive maintenance for main water pump.',
        category: TaskCategory.preventiveMaintenance,
        priority: TaskPriority.urgent,
        status: TaskStatus.newTask,
        propertyName: instance.branding.propertyName,
        location: 'Pump Room',
        assignedBy: 'System — PM Scheduler',
        assignedDate: now,
        dueDate: DateTime(now.year, now.month, now.day, 10, 30),
      ),
      StaffTask(
        id: 'WO-2026-0079',
        taskNumber: 'WO-2026-0079',
        title: 'Replace Faulty Smoke Detector',
        description: 'Smoke detector in lobby beeping intermittently.',
        category: TaskCategory.workOrder,
        priority: TaskPriority.normal,
        status: TaskStatus.inProgress,
        propertyName: instance.branding.propertyName,
        location: 'Main Lobby',
        assignedBy: 'Property Admin — Siti Aminah',
        assignedDate: now.subtract(const Duration(days: 1)),
        dueDate: now.add(const Duration(days: 1)),
      ),
      StaffTask(
        id: 'WO-2026-0071',
        taskNumber: 'WO-2026-0071',
        title: 'Leaking Pipe Under Sink',
        description: 'Unit A-11-02 reported a slow leak under the kitchen sink.',
        category: TaskCategory.workOrder,
        priority: TaskPriority.high,
        status: TaskStatus.pendingVerification,
        propertyName: instance.branding.propertyName,
        location: 'Block A — Unit 11-02',
        assignedBy: 'Property Admin — Siti Aminah',
        assignedDate: now.subtract(const Duration(days: 2)),
        dueDate: now.subtract(const Duration(hours: 4)),
        completionRemarks: 'Replaced worn washer and tightened fitting. Tested for 10 minutes, no leak.',
        materialsUsed: 'Rubber washer x1, PTFE tape',
        timeSpentMinutes: 45,
        afterPhotos: [
          EvidencePhoto(
            id: 'after_1',
            url: 'https://images.unsplash.com/photo-1585704032915-c3400ca199e7?w=800',
            uploadedAt: now.subtract(const Duration(hours: 4)),
          ),
        ],
      ),
      StaffTask(
        id: 'ICA-2026-0330',
        taskNumber: 'ICA-2026-0330',
        title: 'Broken Handrail on Fire Escape',
        description: 'Handrail bracket loose on level 5 fire escape stairwell.',
        category: TaskCategory.inspectionCorrectiveAction,
        priority: TaskPriority.high,
        status: TaskStatus.rejected,
        propertyName: instance.branding.propertyName,
        location: 'Block A — Level 5',
        assignedBy: 'Inspector — Kumar Raj',
        assignedDate: now.subtract(const Duration(days: 3)),
        dueDate: now.subtract(const Duration(days: 1)),
        rejectionReason: 'Please retake the photo showing the entire staircase and handrail bracket.',
        completionRemarks: 'Re-tightened bracket bolts.',
        afterPhotos: [
          EvidencePhoto(
            id: 'after_rej_1',
            url: 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?w=800',
            uploadedAt: now.subtract(const Duration(days: 1)),
          ),
        ],
        inspectionIssue: InspectionIssue(
          issue: 'Broken handrail bracket',
          location: 'Block A — Level 5',
          severity: 'High',
          inspectorRemark: 'Bracket is loose, safety risk for residents.',
          beforePhotos: [
            EvidencePhoto(
              id: 'before_2',
              url: 'https://images.unsplash.com/photo-1523419409543-8c1a91125d5b?w=800',
              uploadedAt: now.subtract(const Duration(days: 3)),
              uploadedByRole: 'Inspector',
            ),
          ],
        ),
      ),
      StaffTask(
        id: 'WO-2026-0065',
        taskNumber: 'WO-2026-0065',
        title: 'Garden Sprinkler Repair',
        description: 'Sprinkler head damaged near playground.',
        category: TaskCategory.workOrder,
        priority: TaskPriority.low,
        status: TaskStatus.verified,
        propertyName: instance.branding.propertyName,
        location: 'Garden Area',
        assignedBy: 'Property Admin — Siti Aminah',
        assignedDate: now.subtract(const Duration(days: 5)),
        dueDate: now.subtract(const Duration(days: 4)),
        completionRemarks: 'Replaced sprinkler head.',
      ),
    ];
  }

  static List<PmTask> _buildPmTasks() {
    final now = DateTime.now();
    return [
      PmTask(
        id: 'PM-ASSET-001',
        assetName: 'Main Water Pump',
        assetId: 'AST-0044',
        location: 'Pump Room',
        pmType: 'Monthly Inspection',
        scheduledDate: DateTime(now.year, now.month, now.day, 10, 30),
        instructions: 'Follow lock-out/tag-out procedure before inspecting the pump.',
        status: PmStatus.today,
        checklist: const [
          PmChecklistItem(id: 'c1', label: 'Inspect pump condition'),
          PmChecklistItem(id: 'c2', label: 'Check abnormal noise'),
          PmChecklistItem(id: 'c3', label: 'Check leakage'),
          PmChecklistItem(id: 'c4', label: 'Check control panel'),
          PmChecklistItem(id: 'c5', label: 'Take evidence photo'),
        ],
      ),
      PmTask(
        id: 'PM-ASSET-002',
        assetName: 'Lift Car 1',
        assetId: 'AST-0012',
        location: 'Block A Lobby',
        pmType: 'Quarterly Service',
        scheduledDate: now.add(const Duration(days: 3)),
        instructions: 'Coordinate with lift vendor if certified technician is required.',
        status: PmStatus.upcoming,
        checklist: const [
          PmChecklistItem(id: 'c1', label: 'Check door sensors'),
          PmChecklistItem(id: 'c2', label: 'Check emergency phone'),
          PmChecklistItem(id: 'c3', label: 'Lubricate rails'),
        ],
      ),
      PmTask(
        id: 'PM-ASSET-003',
        assetName: 'Fire Extinguisher — Block B L1',
        assetId: 'AST-0091',
        location: 'Block B — Level 1',
        pmType: 'Monthly Check',
        scheduledDate: now.subtract(const Duration(days: 2)),
        instructions: 'Verify pressure gauge and inspection tag.',
        status: PmStatus.overdue,
        checklist: const [
          PmChecklistItem(id: 'c1', label: 'Check pressure gauge'),
          PmChecklistItem(id: 'c2', label: 'Check inspection tag'),
          PmChecklistItem(id: 'c3', label: 'Take evidence photo'),
        ],
      ),
      PmTask(
        id: 'PM-ASSET-004',
        assetName: 'Rooftop Generator',
        assetId: 'AST-0003',
        location: 'Rooftop',
        pmType: 'Weekly Test Run',
        scheduledDate: now.subtract(const Duration(days: 6)),
        instructions: 'Run generator for 10 minutes under no load.',
        status: PmStatus.completed,
        checklist: const [
          PmChecklistItem(id: 'c1', label: 'Check oil level', isChecked: true),
          PmChecklistItem(id: 'c2', label: 'Test run 10 minutes', isChecked: true),
        ],
      ),
    ];
  }

  static List<AppNotification> _buildNotifications() {
    final now = DateTime.now();
    return [
      AppNotification(
        id: 'N1',
        type: NotificationType.urgentTask,
        title: 'New Work Order Assigned',
        body: 'Water leakage reported at Block A.',
        createdAt: now.subtract(const Duration(minutes: 5)),
        deepLinkRoute: '/tasks/WO-2026-0082',
      ),
      AppNotification(
        id: 'N2',
        type: NotificationType.taskRejected,
        title: 'Task Requires Attention — ICA-2026-0330',
        body: 'Completion evidence was rejected. Reason: Please retake the photo showing the entire staircase.',
        createdAt: now.subtract(const Duration(hours: 2)),
        deepLinkRoute: '/tasks/ICA-2026-0330',
      ),
      AppNotification(
        id: 'N3',
        type: NotificationType.pmDue,
        title: 'PM Due Today',
        body: 'Water Pump Inspection is due at 10:30 AM.',
        createdAt: now.subtract(const Duration(hours: 4)),
        deepLinkRoute: '/pm/PM-ASSET-001',
        isRead: true,
      ),
      AppNotification(
        id: 'N4',
        type: NotificationType.taskVerified,
        title: 'Task Verified — WO-2026-0065',
        body: 'Your completed work has been verified by the Property Admin.',
        createdAt: now.subtract(const Duration(days: 1)),
        isRead: true,
      ),
      AppNotification(
        id: 'N5',
        type: NotificationType.managementAnnouncement,
        title: 'Water supply maintenance this weekend',
        body: 'Main water supply will be interrupted Saturday 9AM-12PM.',
        createdAt: now.subtract(const Duration(hours: 5)),
        isRead: true,
      ),
    ];
  }

  static List<PropertyAsset> _buildAssets() {
    final now = DateTime.now();
    return [
      PropertyAsset(
        id: 'AST-0044',
        name: 'Main Water Pump',
        propertyName: instance.branding.propertyName,
        location: 'Pump Room',
        status: 'Operational',
        lastMaintenanceDate: now.subtract(const Duration(days: 28)),
        nextMaintenanceDate: DateTime(now.year, now.month, now.day, 10, 30),
      ),
      PropertyAsset(
        id: 'AST-0012',
        name: 'Lift Car 1',
        propertyName: instance.branding.propertyName,
        location: 'Block A Lobby',
        status: 'Operational',
        lastMaintenanceDate: now.subtract(const Duration(days: 60)),
        nextMaintenanceDate: now.add(const Duration(days: 3)),
      ),
    ];
  }

  static List<AttendanceRecord> _buildAttendanceHistory() {
    final now = DateTime.now();
    final rnd = Random(7);
    return List.generate(12, (i) {
      final day = now.subtract(Duration(days: i + 1));
      if (day.weekday == DateTime.sunday) {
        return AttendanceRecord(date: day, hoursWorked: 0, isLate: false, overtimeMinutes: 0);
      }
      final lateMinutes = rnd.nextInt(20);
      final clockIn = DateTime(day.year, day.month, day.day, 8, lateMinutes);
      final overtime = rnd.nextBool() ? rnd.nextInt(60) : 0;
      final clockOut = DateTime(day.year, day.month, day.day, 17, overtime);
      return AttendanceRecord(
        date: day,
        clockIn: clockIn,
        clockOut: clockOut,
        hoursWorked: clockOut.difference(clockIn).inMinutes / 60,
        isLate: lateMinutes > 5,
        overtimeMinutes: overtime,
      );
    });
  }
}
