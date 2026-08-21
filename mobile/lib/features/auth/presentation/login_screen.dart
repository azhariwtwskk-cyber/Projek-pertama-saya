import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../application/auth_providers.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _usernameController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _rememberMe = false;
  bool _submitting = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    ref.listenManual(rememberedUsernameProvider, (prev, next) {
      next.whenData((username) {
        if (username != null && username.isNotEmpty) {
          setState(() {
            _usernameController.text = username;
            _rememberMe = true;
          });
        }
      });
    });
  }

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _submitting = true;
      _errorMessage = null;
    });
    final success = await ref.read(authControllerProvider.notifier).login(
          usernameOrEmail: _usernameController.text.trim(),
          password: _passwordController.text,
          rememberMe: _rememberMe,
        );
    if (!mounted) return;
    setState(() => _submitting = false);
    if (!success) {
      setState(() => _errorMessage =
          ref.read(authControllerProvider).errorMessage ??
              'Incorrect username or password.');
    }
  }

  void _showForgotPassword() {
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Forgot Password'),
        content: const Text(
          'Please contact your Property Admin or CPMSPro helpdesk to reset your password.',
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context), child: const Text('OK')),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) => SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
            child: ConstrainedBox(
              constraints:
                  BoxConstraints(minHeight: constraints.maxHeight - 64),
              child: IntrinsicHeight(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: 24),
                    Center(
                      child: Container(
                        width: 84,
                        height: 84,
                        decoration: BoxDecoration(
                          color: theme.colorScheme.primary,
                          borderRadius: BorderRadius.circular(24),
                          boxShadow: [
                            BoxShadow(
                              color: theme.colorScheme.primary
                                  .withValues(alpha: 0.25),
                              blurRadius: 24,
                              offset: const Offset(0, 10),
                            ),
                          ],
                        ),
                        child: const Icon(Icons.apartment_rounded,
                            color: Colors.white, size: 42),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Text(
                      'CPMSPro Workforce',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Property Operations. Simplified.',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium
                          ?.copyWith(color: AppColors.textSecondary),
                    ),
                    const SizedBox(height: 40),
                    Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Text('Username / Email',
                              style: theme.textTheme.labelLarge),
                          const SizedBox(height: 8),
                          TextFormField(
                            controller: _usernameController,
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              hintText: 'name@cpmspro.com',
                              prefixIcon: Icon(Icons.person_outline_rounded),
                            ),
                            validator: (v) => (v == null || v.trim().isEmpty)
                                ? 'Enter your username or email'
                                : null,
                          ),
                          const SizedBox(height: 18),
                          Text('Password', style: theme.textTheme.labelLarge),
                          const SizedBox(height: 8),
                          TextFormField(
                            controller: _passwordController,
                            obscureText: _obscurePassword,
                            textInputAction: TextInputAction.done,
                            onFieldSubmitted: (_) => _submit(),
                            decoration: InputDecoration(
                              hintText: 'Enter your password',
                              prefixIcon:
                                  const Icon(Icons.lock_outline_rounded),
                              suffixIcon: IconButton(
                                icon: Icon(_obscurePassword
                                    ? Icons.visibility_outlined
                                    : Icons.visibility_off_outlined),
                                onPressed: () => setState(
                                    () => _obscurePassword = !_obscurePassword),
                              ),
                            ),
                            validator: (v) => (v == null || v.isEmpty)
                                ? 'Enter your password'
                                : null,
                          ),
                          if (_errorMessage != null) ...[
                            const SizedBox(height: 14),
                            Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: AppColors.danger.withValues(alpha: 0.08),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Row(
                                children: [
                                  const Icon(Icons.error_outline_rounded,
                                      color: AppColors.danger, size: 18),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(_errorMessage!,
                                        style: const TextStyle(
                                            color: AppColors.danger,
                                            fontSize: 13)),
                                  ),
                                ],
                              ),
                            ),
                          ],
                          const SizedBox(height: 12),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              InkWell(
                                onTap: () =>
                                    setState(() => _rememberMe = !_rememberMe),
                                borderRadius: BorderRadius.circular(8),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Checkbox(
                                      value: _rememberMe,
                                      onChanged: (v) => setState(
                                          () => _rememberMe = v ?? false),
                                      visualDensity: VisualDensity.compact,
                                    ),
                                    const Text('Remember Me'),
                                  ],
                                ),
                              ),
                              TextButton(
                                  onPressed: _showForgotPassword,
                                  child: const Text('Forgot Password?')),
                            ],
                          ),
                          const SizedBox(height: 12),
                          ElevatedButton(
                            onPressed: _submitting ? null : _submit,
                            child: _submitting
                                ? const SizedBox(
                                    height: 22,
                                    width: 22,
                                    child: CircularProgressIndicator(
                                        strokeWidth: 2.4, color: Colors.white),
                                  )
                                : const Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Icon(Icons.lock_rounded,
                                          size: 18, color: Colors.white),
                                      SizedBox(width: 8),
                                      Text('Secure Login'),
                                    ],
                                  ),
                          ),
                        ],
                      ),
                    ),
                    const Spacer(),
                    const SizedBox(height: 24),
                    Center(
                      child: Text(
                        'Protected by CPMSPro secure authentication',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: AppColors.textSecondary),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
