import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/network/api_client.dart';
import '../../domain/entities/inbox_conversation_detail.dart';
import '../../domain/entities/inbox_conversation_item.dart';
import '../../domain/repositories/inbox_repository.dart';
import '../datasources/inbox_remote_datasource.dart';

final inboxRemoteDatasourceProvider = Provider<InboxRemoteDatasource>((ref) {
  return InboxRemoteDatasource(ref.watch(dioProvider));
});

final inboxRepositoryProvider = Provider<InboxRepository>((ref) {
  return InboxRepositoryImpl(ref.watch(inboxRemoteDatasourceProvider));
});

class InboxRepositoryImpl implements InboxRepository {
  const InboxRepositoryImpl(this._remoteDatasource);

  final InboxRemoteDatasource _remoteDatasource;

  @override
  Future<List<InboxConversationItem>> getConversations({String? search}) {
    return _remoteDatasource.getConversations(search: search);
  }

  @override
  Future<InboxConversationDetail> getConversationDetail(int conversationId) {
    return _remoteDatasource.getConversationDetail(conversationId);
  }
}
