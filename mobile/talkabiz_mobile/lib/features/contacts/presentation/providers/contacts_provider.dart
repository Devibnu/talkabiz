import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/contacts_repository_impl.dart';
import '../../domain/entities/contact_item.dart';

final contactsSearchProvider = StateProvider<String>((ref) => '');

final contactsProvider = FutureProvider<List<ContactItem>>((ref) async {
  final search = ref.watch(contactsSearchProvider);
  return ref.watch(contactsRepositoryProvider).getContacts(search: search);
});
