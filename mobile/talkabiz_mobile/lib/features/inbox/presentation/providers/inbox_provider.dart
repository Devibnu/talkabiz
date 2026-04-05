import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/inbox_repository_impl.dart';
import '../../domain/entities/inbox_conversation_detail.dart';
import '../../domain/entities/inbox_conversation_item.dart';
import '../../domain/repositories/inbox_repository.dart';

final inboxSearchProvider = StateProvider<String>((ref) => '');

class InboxComposerState {
  const InboxComposerState({this.isSubmitting = false, this.errorMessage});

  final bool isSubmitting;
  final String? errorMessage;

  InboxComposerState copyWith({
    bool? isSubmitting,
    String? errorMessage,
    bool clearError = false,
  }) {
    return InboxComposerState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
    );
  }
}

final inboxComposerProvider = StateNotifierProvider.autoDispose
    .family<InboxComposerController, InboxComposerState, int>((
      ref,
      conversationId,
    ) {
      return InboxComposerController(
        repository: ref.watch(inboxRepositoryProvider),
        conversationId: conversationId,
      );
    });

final inboxProvider = FutureProvider<List<InboxConversationItem>>((ref) async {
  final search = ref.watch(inboxSearchProvider);
  return ref.watch(inboxRepositoryProvider).getConversations(search: search);
});

final inboxConversationDetailProvider =
    FutureProvider.family<InboxConversationDetail, int>((
      ref,
      conversationId,
    ) async {
      return ref
          .watch(inboxRepositoryProvider)
          .getConversationDetail(conversationId);
    });

class InboxComposerController extends StateNotifier<InboxComposerState> {
  InboxComposerController({
    required this.repository,
    required this.conversationId,
  }) : super(const InboxComposerState());

  final InboxRepository repository;
  final int conversationId;

  Future<bool> sendMessage(String rawMessage) async {
    final message = rawMessage.trim();
    if (message.isEmpty) {
      state = state.copyWith(errorMessage: 'Pesan tidak boleh kosong.');
      return false;
    }

    state = state.copyWith(isSubmitting: true, clearError: true);

    try {
      await repository.sendMessage(
        conversationId: conversationId,
        message: message,
      );
      state = const InboxComposerState();
      return true;
    } catch (_) {
      state = state.copyWith(
        isSubmitting: false,
        errorMessage: 'Gagal mengirim pesan. Silakan coba lagi.',
      );
      return false;
    }
  }
}
