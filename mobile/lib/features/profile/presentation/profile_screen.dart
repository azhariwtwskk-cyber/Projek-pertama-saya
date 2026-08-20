import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/section_header.dart';
import '../../auth/application/auth_providers.dart';
import '../../sync/presentation/sync_centre_screen.dart';

/// Every item below was audited against the real CPMSPro backend
/// (mobile/docs/INTEGRATION_REPAIR_REPORT.md, Settings audit). Only Sync
/// Centre and Logout call anything live; About/Help/Privacy are static,
/// honest, local content; everything the backend has no endpoint for
/// (Edit Profile, Change Password, Language, Notification Settings) is
/// shown disabled with a clear reason instead of a silently-dead button.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Log Out'),
        content: const Text(
            'Are you sure you want to log out of CPMSPro Workforce?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Log Out',
                style: TextStyle(color: AppColors.danger)),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  void _showInfoDialog(BuildContext context,
      {required String title, required String body}) {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Close'))
        ],
      ),
    );
  }

  void _showUnavailable(BuildContext context, String feature) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
          content: Text(
              '$feature is not available in this app yet — it is not supported by the CPMSPro server API.')),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentStaffUserProvider);
    if (user == null) return const SizedBox.shrink();

    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AppSectionCard(
            child: Column(
              children: [
                CircleAvatar(
                  radius: 40,
                  backgroundColor: Theme.of(context)
                      .colorScheme
                      .primary
                      .withValues(alpha: 0.12),
                  backgroundImage: user.profileImageUrl.isNotEmpty
                      ? NetworkImage(user.profileImageUrl)
                      : null,
                  child: user.profileImageUrl.isEmpty
                      ? Text(user.name.isNotEmpty ? user.name[0] : '?',
                          style: TextStyle(
                              fontSize: 28,
                              fontWeight: FontWeight.w800,
                              color: Theme.of(context).colorScheme.primary))
                      : null,
                ),
                const SizedBox(height: 12),
                Text(user.name,
                    style: const TextStyle(
                        fontWeight: FontWeight.w800, fontSize: 18)),
                const SizedBox(height: 2),
                Text(user.role,
                    style: const TextStyle(color: AppColors.textSecondary)),
                const SizedBox(height: 16),
                const Divider(),
                const SizedBox(height: 8),
                // The `staff` table has no distinct employee_id column
                // (mobile/docs/INTEGRATION_REPAIR_REPORT.md) — the account
                // id is shown honestly as "Staff ID" rather than implying
                // a real employee number the backend doesn't have.
                _InfoRow(
                    icon: Icons.badge_outlined,
                    label: 'Staff ID',
                    value: user.employeeId),
                _InfoRow(
                    icon: Icons.apartment_rounded,
                    label: 'Property',
                    value: user.branding.propertyName),
                _InfoRow(
                    icon: Icons.phone_outlined,
                    label: 'Phone',
                    value: user.phone.isEmpty ? '—' : user.phone),
                _InfoRow(
                    icon: Icons.email_outlined,
                    label: 'Email',
                    value: user.email.isEmpty ? '—' : user.email),
              ],
            ),
          ),
          const SizedBox(height: 20),
          _SettingsGroup(items: [
            _SettingsItem(
              icon: Icons.edit_outlined,
              label: 'Edit Profile',
              enabled: false,
              trailing: 'Unavailable',
              onTap: () => _showUnavailable(context, 'Edit Profile'),
            ),
            _SettingsItem(
              icon: Icons.lock_reset_rounded,
              label: 'Change Password',
              enabled: false,
              trailing: 'Unavailable',
              onTap: () => _showUnavailable(context, 'Change Password'),
            ),
            _SettingsItem(
              icon: Icons.language_rounded,
              label: 'Language',
              enabled: false,
              trailing: 'English',
              onTap: () => _showUnavailable(context, 'Language switching'),
            ),
            _SettingsItem(
              icon: Icons.sync_rounded,
              label: 'Sync Centre',
              onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const SyncCentreScreen())),
            ),
            _SettingsItem(
              icon: Icons.notifications_none_rounded,
              label: 'Notification Settings',
              enabled: false,
              trailing: 'Unavailable',
              onTap: () =>
                  _showUnavailable(context, 'Push notification settings'),
            ),
          ]),
          const SizedBox(height: 16),
          _SettingsGroup(items: [
            _SettingsItem(
              icon: Icons.help_outline_rounded,
              label: 'Help & Support',
              onTap: () => _showInfoDialog(
                context,
                title: 'Help & Support',
                body:
                    'For login issues, task problems or anything else, contact your Property Admin or the CPMSPro support team through your usual property channel.',
              ),
            ),
            _SettingsItem(
              icon: Icons.privacy_tip_outlined,
              label: 'Privacy',
              onTap: () => _showInfoDialog(
                context,
                title: 'Privacy',
                body:
                    'CPMSPro Workforce only collects the data needed to run your Staff duties: your login session, GPS location at the moment of clock-in/out, and photos you choose to attach as work evidence. Location is never tracked continuously.',
              ),
            ),
            _SettingsItem(
              icon: Icons.info_outline_rounded,
              label: 'About CPMSPro',
              onTap: () => _showInfoDialog(
                context,
                title: 'About CPMSPro',
                body:
                    'CPMSPro Workforce Staff App\nVersion ${AppConfig.appVersion}\nProperty: ${user.branding.propertyName}',
              ),
            ),
          ]),
          const SizedBox(height: 16),
          _SettingsGroup(items: [
            _SettingsItem(
                icon: Icons.logout_rounded,
                label: 'Logout',
                destructive: true,
                onTap: () => _confirmLogout(context, ref)),
          ]),
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow(
      {required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 18, color: AppColors.textSecondary),
          const SizedBox(width: 10),
          Expanded(
              child: Text(label,
                  style: const TextStyle(color: AppColors.textSecondary))),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _SettingsGroup extends StatelessWidget {
  const _SettingsGroup({required this.items});
  final List<_SettingsItem> items;

  @override
  Widget build(BuildContext context) {
    return AppSectionCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < items.length; i++) ...[
            items[i],
            if (i != items.length - 1) const Divider(height: 1, indent: 56),
          ],
        ],
      ),
    );
  }
}

class _SettingsItem extends StatelessWidget {
  const _SettingsItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.trailing,
    this.destructive = false,
    this.enabled = true,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final String? trailing;
  final bool destructive;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final color = destructive
        ? AppColors.danger
        : (enabled ? AppColors.textPrimary : AppColors.textSecondary);
    return Opacity(
      opacity: enabled ? 1 : 0.6,
      child: ListTile(
        onTap: onTap,
        leading: Icon(icon,
            color: destructive ? AppColors.danger : AppColors.textSecondary),
        title: Text(label,
            style: TextStyle(color: color, fontWeight: FontWeight.w600)),
        trailing: trailing != null
            ? Text(trailing!,
                style: const TextStyle(
                    color: AppColors.textSecondary, fontSize: 12.5))
            : (enabled
                ? const Icon(Icons.chevron_right_rounded,
                    color: AppColors.textSecondary)
                : null),
      ),
    );
  }
}
