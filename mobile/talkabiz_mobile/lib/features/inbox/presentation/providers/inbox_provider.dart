import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/repositories/inbox_repository_impl.dart';
import '../../domain/entities/inbox_conversation_detail.dart';
import '../../domain/entities/inbox_conversation_item.dart';
import '../../domain/repositories/inbox_repository.dart';

final inboxSearchProvider = StateProvider<String>((ref) => '');

class InboxComposerState {
  const InboxComposerState({
    this.isSubmitting = false,
    this.isUploading = false,
    this.errorMessage,
    this.attachedFilePath,
    this.attachedMediaType,
    this.attachedFileName,
  });

  final bool isSubmitting;
  final bool isUploading;
  final String? errorMessage;
  final String? attachedFilePath;
  final String? attachedMediaType;
  final String? attachedFileName;

  bool get hasAttachment => attachedFilePath != null;

  InboxComposerState copyWith({
    bool? isSubmitting,
    bool? isUploading,
    String? errorMessage,
    String? attachedFilePath,
    String? attachedMediaType,
    String? attachedFileName,
    bool clearError = false,
    bool clearAttachment = false,
  }) {
    return InboxComposerState(
      isSubmitting: isSubmitting ?? this.isSubmitting,
      isUploading: isUploading ?? this.isUploading,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      attachedFilePath: clearAttachment ? null : (attachedFilePath ?? this.attachedFilePath),
      attachedMediaType: clearAttachment ? null : (attachedMediaType ?? this.attachedMediaType),
      attachedFileName: clearAttachment ? null : (attachedFileName ?? this.attachedFileName),
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

  void attachFile(String path, String fileName, String mediaType) {
    state = state.copyWith(
      attachedFilePath: path,
      attachedFileName: fileName,
      attachedMediaType: mediaType,
      clearError: true,
    );
  }

  void removeAttachment() {
    state = state.copyWith(clearAttachment: true);
  }

  Future<bool> sendMessage(String rawMessage) async {
    final message = rawMessage.trim();
    final hasAttachment = state.hasAttachment;

    if (message.isEmpty && !hasAttachment) {
      state = state.copyWith(errorMessage: 'Pesan tidak boleh kosong.');
      return false;
    }

    state = state.copyWith(isSubmitting: true, clearError: true);

    try {
      String? mediaUrl;
      String? mediaType;

      if (hasAttachment) {
        state = state.copyWith(isUploading: true);
        final result = await repository.uploadMedia(state.attachedFilePath!);
        mediaUrl = result.url;
        mediaType = result.mediaType;
        state = state.copyWith(isUploading: false);
      }

      await repository.sendMessage(
        conversationId: conversationId,
        message: message,
        type: mediaType,
        mediaUrl: mediaUrl,
      );
      state = const InboxComposerState();
      return true;
    } catch (_) {
      state = state.copyWith(
        isSubmitting: false,
        isUploading: false,
        errorMessage: 'Gagal mengirim pesan. Silakan coba lagi.',
      );
      return false;
    }
  }
}
