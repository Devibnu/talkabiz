import '../entities/contact_item.dart';

abstract class ContactsRepository {
  Future<List<ContactItem>> getContacts({String? search});
}
