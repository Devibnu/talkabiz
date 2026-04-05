import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../domain/entities/contact_item.dart';
import '../../domain/repositories/contacts_repository.dart';
import '../datasources/contacts_remote_datasource.dart';

final contactsRemoteDatasourceProvider = Provider<ContactsRemoteDatasource>((
  ref,
) {
  return ContactsRemoteDatasource(ref.watch(dioProvider));
});

final contactsRepositoryProvider = Provider<ContactsRepository>((ref) {
  return ContactsRepositoryImpl(ref.watch(contactsRemoteDatasourceProvider));
});

class ContactsRepositoryImpl implements ContactsRepository {
  const ContactsRepositoryImpl(this._remoteDatasource);

  final ContactsRemoteDatasource _remoteDatasource;

  @override
  Future<List<ContactItem>> getContacts({String? search}) {
    return _remoteDatasource.getContacts(search: search);
  }
}
