import 'package:flutter_test/flutter_test.dart';

import 'package:talkabiz_mobile/app/theme/app_theme.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('app theme can be created', () {
    expect(AppTheme.light(), isNotNull);
  });
}
