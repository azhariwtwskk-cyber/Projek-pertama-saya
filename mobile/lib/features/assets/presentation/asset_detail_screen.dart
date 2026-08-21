import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/empty_state.dart';
import '../../../shared/widgets/section_header.dart';
import '../application/assets_providers.dart';

/// Section 23: post-scan asset actions — View Asset (this screen), Start
/// PM, Report Problem, View Maintenance History.
class AssetDetailScreen extends ConsumerWidget {
  const AssetDetailScreen({super.key, required this.assetId});
  final String assetId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final assetAsync = ref.watch(assetDetailProvider(assetId));

    return Scaffold(
      appBar: AppBar(title: const Text('Asset Details')),
      body: assetAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(
            child: AppStateView.error(
                message: 'Asset not found for this property.',
                onRetry: () => ref.invalidate(assetDetailProvider(assetId)))),
        data: (asset) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            AppSectionCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 48,
                        height: 48,
                        decoration: BoxDecoration(
                          color: Theme.of(context)
                              .colorScheme
                              .primary
                              .withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Icon(Icons.qr_code_2_rounded,
                            color: Theme.of(context).colorScheme.primary),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(asset.name,
                                style: const TextStyle(
                                    fontWeight: FontWeight.w800, fontSize: 17)),
                            Text(asset.id,
                                style: const TextStyle(
                                    color: AppColors.textSecondary,
                                    fontSize: 12)),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 28),
                  _Row(label: 'Property', value: asset.propertyName),
                  _Row(label: 'Location', value: asset.location),
                  _Row(
                      label: 'Status',
                      value: asset.status,
                      valueColor: AppColors.success),
                  _Row(
                      label: 'Last Maintenance',
                      value: asset.lastMaintenanceDate == null
                          ? '—'
                          : DateFormat('d MMM yyyy')
                              .format(asset.lastMaintenanceDate!)),
                  _Row(
                      label: 'Next Maintenance',
                      value: asset.nextMaintenanceDate == null
                          ? '—'
                          : DateFormat('d MMM yyyy')
                              .format(asset.nextMaintenanceDate!)),
                ],
              ),
            ),
            const SizedBox(height: 20),
            const SectionHeader(title: 'Actions'),
            Row(
              children: [
                Expanded(
                  child: _ActionButton(
                      icon: Icons.build_rounded,
                      label: 'Start PM',
                      onTap: () => context.push('/pm')),
                ),
                const SizedBox(width: 12),
                Expanded(
                    child: _ActionButton(
                        icon: Icons.report_problem_outlined,
                        label: 'Report Problem',
                        onTap: () => context.push('/daily-work/add'))),
              ],
            ),
            const SizedBox(height: 12),
            _ActionButton(
                icon: Icons.history_rounded,
                label: 'View Maintenance History',
                onTap: () {},
                fullWidth: true),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value, this.valueColor});
  final String label;
  final String value;
  final Color? valueColor;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        children: [
          Text('$label: ',
              style: const TextStyle(
                  color: AppColors.textSecondary, fontWeight: FontWeight.w600)),
          Expanded(
              child: Text(value,
                  style: TextStyle(
                      fontWeight: FontWeight.w600, color: valueColor))),
        ],
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton(
      {required this.icon,
      required this.label,
      required this.onTap,
      this.fullWidth = false});
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool fullWidth;

  @override
  Widget build(BuildContext context) {
    final button = OutlinedButton.icon(
        onPressed: onTap, icon: Icon(icon, size: 18), label: Text(label));
    return fullWidth ? SizedBox(width: double.infinity, child: button) : button;
  }
}
