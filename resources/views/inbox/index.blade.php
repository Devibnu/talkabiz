@extends('layouts.user_type.auth')

@push('styles')
<style>
/* ============================================
   TALKABIZ INBOX - Soft UI Dashboard Style
   100% Konsisten dengan Soft UI Design System
   ============================================ */

/* CSS Variables - Official Soft UI Palette */
:root {
    /* Soft UI Primary Colors */
    --soft-primary: #5e72e4;
    --soft-secondary: #825ee4;
    --soft-info: #17c1e8;
    --soft-success: #82d616;
    --soft-warning: #fbcf33;
    --soft-danger: #ea0606;
    
    /* Soft UI Gradients */
    --soft-gradient-primary: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    --soft-gradient-success: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    --soft-gradient-info: linear-gradient(310deg, #2152ff 0%, #21d4fd 100%);
    --soft-gradient-warning: linear-gradient(310deg, #f53939 0%, #fbcf33 100%);
    --soft-gradient-dark: linear-gradient(310deg, #141727 0%, #3a416f 100%);
    --soft-gradient-whatsapp: linear-gradient(310deg, #25D366 0%, #128C7E 100%);
    
    /* Soft UI Text Colors */
    --soft-text-dark: #344767;
    --soft-text-body: #67748e;
    --soft-text-secondary: #8392ab;
    --soft-text-muted: #a8b8d8;
    
    /* Soft UI Background */
    --soft-bg-body: #f8f9fa;
    --soft-bg-white: #ffffff;
    --soft-bg-chat: #f0f2f5;
    --soft-bg-pattern: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2367748e' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    
    /* Soft UI Border */
    --soft-border: #e9ecef;
    --soft-border-light: #f0f2f5;
    
    /* Soft UI Shadows */
    --soft-shadow-sm: 0 .25rem .375rem -.0625rem hsla(0,0%,8%,.12),0 .125rem .25rem -.0625rem hsla(0,0%,8%,.07);
    --soft-shadow-md: 0 4px 6px -1px hsla(0,0%,8%,.1),0 2px 4px -1px hsla(0,0%,8%,.06);
    --soft-shadow-lg: 0 8px 26px -4px hsla(0,0%,8%,.15),0 8px 9px -5px hsla(0,0%,8%,.06);
    --soft-shadow-xl: 0 23px 45px -11px hsla(0,0%,8%,.25);
    
    /* Inbox Specific */
    --inbox-hover: #f8f9fa;
    --inbox-active: #eef2ff;
    --inbox-bubble-out: linear-gradient(310deg, #e9ecef 0%, #f0f2f5 100%);
}

/* Main Container */
.inbox-container {
    display: flex;
    height: calc(100vh - 140px);
    background: var(--soft-bg-white);
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: var(--soft-shadow-lg);
    border: 0;
}

/* ============================================
   LEFT PANEL - CONVERSATION LIST
   ============================================ */
.inbox-list {
    width: 360px;
    min-width: 360px;
    display: flex;
    flex-direction: column;
    background: var(--soft-bg-white);
    border-right: 1px solid var(--soft-border);
}

/* List Header */
.inbox-list-header {
    padding: 1.25rem;
    background: var(--soft-bg-white);
    border-bottom: 1px solid var(--soft-border);
}

/* Search Box - Soft UI Style */
.inbox-search {
    position: relative;
}

.inbox-search input {
    width: 100%;
    height: 2.75rem;
    padding: 0 1rem 0 3rem;
    border: 1px solid var(--soft-border);
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: var(--soft-bg-white);
    color: var(--soft-text-dark);
    transition: all 0.2s ease-in-out;
}

.inbox-search input::placeholder {
    color: var(--soft-text-secondary);
}

.inbox-search input:focus {
    outline: none;
    border-color: var(--soft-primary);
    box-shadow: 0 0 0 2px rgba(94, 114, 228, 0.25);
}

.inbox-search i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--soft-text-secondary);
    font-size: 0.875rem;
    transition: color 0.2s ease-in-out;
}

.inbox-search input:focus + i,
.inbox-search:focus-within i {
    color: var(--soft-primary);
}

/* Filter Pills - Soft UI Style */
.inbox-filters {
    display: flex;
    gap: 0.5rem;
    padding: 1rem 1.25rem;
    background: var(--soft-bg-white);
    border-bottom: 1px solid var(--soft-border);
    overflow-x: auto;
    scrollbar-width: none;
}

.inbox-filters::-webkit-scrollbar {
    display: none;
}

.inbox-filter-btn {
    padding: 0.5rem 1rem;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: -0.025rem;
    border: 0;
    background: var(--soft-bg-body);
    color: var(--soft-text-body);
    border-radius: 0.5rem;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.inbox-filter-btn:hover {
    background: var(--soft-border);
    color: var(--soft-text-dark);
}

.inbox-filter-btn.active {
    background: var(--soft-gradient-primary);
    color: #fff;
    border: 0;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

/* Conversation List */
.inbox-conversations {
    flex: 1;
    overflow-y: auto;
    background: var(--soft-bg-white);
}

/* Conversation Item - Soft UI Card Style */
.conversation-item {
    display: flex;
    align-items: center;
    padding: 0.875rem 1rem;
    margin: 0.375rem 0.75rem;
    cursor: pointer;
    border-radius: 0.75rem;
    border-left: 3px solid transparent;
    transition: all 0.15s ease-in-out;
    position: relative;
    background: var(--soft-bg-white);
}

.conversation-item:hover {
    background: var(--inbox-hover);
    box-shadow: var(--soft-shadow-sm);
}

.conversation-item.active {
    background: var(--inbox-active);
    border-left-color: var(--soft-primary);
    box-shadow: var(--soft-shadow-md);
}

/* Avatar - Soft UI Gradient Style */
.conversation-avatar {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    color: #fff;
    margin-right: 0.875rem;
    position: relative;
    box-shadow: var(--soft-shadow-sm);
}

.conversation-avatar .status-indicator {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid var(--soft-bg-white);
    box-shadow: var(--soft-shadow-sm);
}

.status-aktif { background: var(--soft-gradient-success); }
.status-baru { background: var(--soft-gradient-warning); }
.status-locked { background: var(--soft-text-secondary); }

/* Conversation Content */
.conversation-content {
    flex: 1;
    min-width: 0;
}

.conversation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.conversation-name {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--soft-text-dark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 170px;
}

.conversation-time {
    font-size: 0.75rem;
    color: var(--soft-text-secondary);
    white-space: nowrap;
    flex-shrink: 0;
    margin-left: 0.5rem;
}

.conversation-item.unread .conversation-time {
    color: var(--soft-primary);
    font-weight: 700;
}

.conversation-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.conversation-preview {
    font-size: 0.8125rem;
    color: var(--soft-text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    padding-right: 0.5rem;
}

.conversation-item.unread .conversation-preview {
    color: var(--soft-text-dark);
    font-weight: 500;
}

.conversation-badge {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    font-size: 0.6875rem;
    font-weight: 700;
    background: var(--soft-gradient-primary);
    color: #fff;
    border-radius: 0.375rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 2px 6px -1px rgba(94, 114, 228, 0.4);
}

/* ============================================
   CENTER PANEL - CHAT WINDOW
   ============================================ */
.inbox-chat {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: var(--soft-bg-chat);
    background-image: var(--soft-bg-pattern);
    min-width: 0;
    position: relative;
}

/* Chat Header - Soft UI Style */
.chat-header {
    height: 4.25rem;
    padding: 0 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--soft-bg-white);
    border-bottom: 1px solid var(--soft-border);
    flex-shrink: 0;
}

.chat-header-info {
    display: flex;
    align-items: center;
}

.chat-header-avatar {
    width: 42px;
    height: 42px;
    border-radius: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    color: #fff;
    margin-right: 0.875rem;
    box-shadow: var(--soft-shadow-sm);
}

.chat-header-details {
    display: flex;
    flex-direction: column;
}

.chat-header-name {
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--soft-text-dark);
    line-height: 1.3;
}

.chat-header-status {
    font-size: 0.75rem;
    color: var(--soft-text-secondary);
}

.chat-header-actions {
    display: flex;
    align-items: center;
    gap: 0.625rem;
}

/* Soft UI Buttons */
.btn-action {
    height: 2.375rem;
    padding: 0 1rem;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: -0.025rem;
    border: none;
    border-radius: 0.5rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.15s ease-in-out;
}

.btn-primary-action {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

.btn-primary-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5), 0 3px 6px -2px rgba(94, 114, 228, 0.3);
}

.btn-secondary-action {
    background: var(--soft-bg-white);
    color: var(--soft-text-dark);
    border: 1px solid var(--soft-border);
}

.btn-secondary-action:hover {
    background: var(--soft-bg-body);
    border-color: var(--soft-text-secondary);
}

/* Chat Messages */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
}

.chat-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--soft-text-secondary);
}

.chat-empty i {
    font-size: 4rem;
    margin-bottom: 1.25rem;
    opacity: 0.3;
    background: var(--soft-gradient-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.chat-empty p {
    font-size: 0.875rem;
    margin: 0;
    color: var(--soft-text-secondary);
}

/* Date Divider - Soft UI Style */
.message-date-divider {
    text-align: center;
    margin: 1.5rem 0;
}

.message-date-divider span {
    display: inline-block;
    padding: 0.5rem 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--soft-text-secondary);
    background: var(--soft-bg-white);
    border-radius: 0.5rem;
    box-shadow: var(--soft-shadow-sm);
}

/* Message Bubbles - Soft UI WhatsApp Style */
.message-bubble {
    max-width: 65%;
    margin-bottom: 0.5rem;
    display: flex;
    flex-direction: column;
}

.message-bubble.incoming {
    align-self: flex-start;
}

.message-bubble.outgoing {
    align-self: flex-end;
}

.message-content {
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    line-height: 1.5;
    word-wrap: break-word;
    position: relative;
}

.message-bubble.incoming .message-content {
    background: var(--soft-bg-white);
    color: var(--soft-text-dark);
    border-radius: 0.25rem 1rem 1rem 1rem;
    box-shadow: var(--soft-shadow-sm);
}

.message-bubble.outgoing .message-content {
    background: var(--inbox-bubble-out);
    color: var(--soft-text-dark);
    border-radius: 1rem 0.25rem 1rem 1rem;
    box-shadow: var(--soft-shadow-sm);
}

.message-meta {
    font-size: 0.6875rem;
    color: var(--soft-text-secondary);
    margin-top: 0.375rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0 0.375rem;
}

.message-bubble.outgoing .message-meta {
    justify-content: flex-end;
}

.message-status i {
    font-size: 0.875rem;
}

.status-sent { color: var(--soft-text-secondary); }
.status-delivered { color: var(--soft-primary); }
.status-read { color: var(--soft-info); }
.status-failed { color: var(--soft-danger); }

/* Chat Composer - Soft UI Fixed Bottom */
.chat-composer {
    padding: 1rem 1.5rem;
    background: var(--soft-bg-white);
    border-top: 1px solid var(--soft-border);
    flex-shrink: 0;
}

.composer-wrapper {
    display: flex;
    align-items: flex-end;
    gap: 0.875rem;
    background: var(--soft-bg-body);
    padding: 0.5rem 0.5rem 0.5rem 1rem;
    border-radius: 1rem;
    border: 1px solid var(--soft-border);
    transition: all 0.15s ease-in-out;
}

.composer-wrapper:focus-within {
    border-color: var(--soft-primary);
    box-shadow: 0 0 0 2px rgba(94, 114, 228, 0.25);
    background: var(--soft-bg-white);
}

.composer-input {
    flex: 1;
}

.composer-input textarea {
    width: 100%;
    min-height: 2.75rem;
    max-height: 7.5rem;
    padding: 0.625rem 0;
    border: none;
    border-radius: 0;
    font-size: 0.875rem;
    line-height: 1.5;
    resize: none;
    background: transparent;
    color: var(--soft-text-dark);
}

.composer-input textarea::placeholder {
    color: var(--soft-text-secondary);
}

.composer-input textarea:focus {
    outline: none;
}

.composer-btn {
    width: 2.5rem;
    height: 2.5rem;
    border: none;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    flex-shrink: 0;
}

.btn-template {
    background: transparent;
    color: var(--soft-text-secondary);
}

.btn-template:hover {
    background: var(--soft-bg-white);
    color: var(--soft-primary);
}

.btn-send {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

.btn-send:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
}

.btn-send:disabled {
    background: var(--soft-border);
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.composer-notice {
    text-align: center;
    padding: 1rem 1.5rem;
    background: var(--soft-gradient-warning);
    border-radius: 0.75rem;
    font-size: 0.8125rem;
    font-weight: 600;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
    border: 0;
    box-shadow: var(--soft-shadow-sm);
}

/* ============================================
   RIGHT PANEL - CONTACT DETAIL
   ============================================ */
.inbox-detail {
    width: 340px;
    min-width: 340px;
    display: flex;
    flex-direction: column;
    background: var(--soft-bg-white);
    border-left: 1px solid var(--soft-border);
    overflow-y: auto;
}

.detail-header {
    padding: 1.75rem 1.5rem;
    text-align: center;
    background: var(--soft-bg-white);
    border-bottom: 1px solid var(--soft-border);
}

.detail-avatar {
    width: 5.5rem;
    height: 5.5rem;
    border-radius: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 2rem;
    color: #fff;
    margin: 0 auto 1rem;
    box-shadow: var(--soft-shadow-lg);
}

.detail-name {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--soft-text-dark);
    margin-bottom: 0.25rem;
}

.detail-phone {
    font-size: 0.875rem;
    color: var(--soft-text-secondary);
    margin-bottom: 0.875rem;
}

.detail-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    justify-content: center;
}

.detail-tag {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 700;
    background: var(--inbox-active);
    color: var(--soft-primary);
    border-radius: 0.375rem;
}

.detail-section {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--soft-border);
}

.detail-section:last-child {
    border-bottom: none;
}

.detail-section-title {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05rem;
    color: var(--soft-text-secondary);
    margin-bottom: 1rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    font-size: 0.8125rem;
}

.detail-label {
    color: var(--soft-text-secondary);
}

.detail-value {
    color: var(--soft-text-dark);
    font-weight: 600;
}

.detail-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-btn {
    width: 100%;
    height: 2.75rem;
    font-size: 0.8125rem;
    font-weight: 700;
    letter-spacing: -0.025rem;
    border: none;
    border-radius: 0.5rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.625rem;
    transition: all 0.15s ease-in-out;
}

.detail-btn-primary {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

.detail-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
}

.detail-btn-secondary {
    background: var(--soft-bg-body);
    color: var(--soft-text-dark);
    border: 1px solid var(--soft-border);
}

.detail-btn-secondary:hover {
    background: var(--soft-border);
    border-color: var(--soft-text-secondary);
}

.detail-btn-danger {
    background: transparent;
    color: var(--soft-danger);
    border: 1px solid rgba(234, 6, 6, 0.3);
}

.detail-btn-danger:hover {
    background: rgba(234, 6, 6, 0.08);
    border-color: var(--soft-danger);
}

/* ============================================
   EMPTY STATE & LOADING
   ============================================ */
.inbox-empty-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2.5rem;
    color: var(--soft-text-secondary);
    text-align: center;
}

.inbox-empty-state i {
    font-size: 3.5rem;
    margin-bottom: 1.25rem;
    opacity: 0.2;
    background: var(--soft-gradient-primary);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.inbox-empty-state p {
    font-size: 0.875rem;
    margin: 0;
    color: var(--soft-text-secondary);
}

/* Enhanced Empty State - List Panel */
.inbox-empty-state .empty-icon-wrapper {
    width: 4rem;
    height: 4rem;
    background: var(--soft-gradient-primary);
    border-radius: 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.inbox-empty-state .empty-icon-wrapper i {
    font-size: 1.75rem;
    color: #fff;
}

.inbox-empty-state .empty-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--soft-text-dark);
    margin-bottom: 0.375rem;
}

.inbox-empty-state .empty-subtitle {
    font-size: 0.8125rem;
    color: var(--soft-text-secondary);
    margin-bottom: 1.25rem;
}

.inbox-empty-state .empty-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: center;
}

.btn-empty-action {
    padding: 0.5rem 1rem;
    font-size: 0.75rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.375rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    border: none;
}

.btn-empty-action.btn-refresh {
    background: var(--soft-bg-body);
    color: var(--soft-text-dark);
    border: 1px solid var(--soft-border);
}

.btn-empty-action.btn-refresh:hover {
    background: var(--soft-border);
}

.btn-empty-action.btn-primary-action {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.btn-empty-action.btn-primary-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
}

/* Loading State */
.inbox-loading-state {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2.5rem;
    color: var(--soft-text-secondary);
    text-align: center;
}

/* Chat Empty Center State */
.chat-empty-center {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    text-align: center;
}

.empty-icon-large {
    width: 5rem;
    height: 5rem;
    background: var(--soft-gradient-primary);
    border-radius: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 16px -4px rgba(94, 114, 228, 0.4);
}

.empty-icon-large i {
    font-size: 2.25rem;
    color: #fff;
}

.empty-title-large {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--soft-text-dark);
    margin-bottom: 0.5rem;
}

.empty-subtitle-large {
    font-size: 0.875rem;
    color: var(--soft-text-secondary);
    margin-bottom: 1.5rem;
    max-width: 280px;
}

.empty-actions-center {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: center;
}

.btn-empty-lg {
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    border: none;
}

.btn-refresh-lg {
    background: var(--soft-bg-white);
    color: var(--soft-text-dark);
    border: 1px solid var(--soft-border);
}

.btn-refresh-lg:hover {
    background: var(--soft-bg-body);
}

.btn-primary-lg {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.btn-primary-lg:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
    color: #fff;
}

/* Chat Header Empty */
.chat-header-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}

/* Composer Disabled State */
.composer-disabled {
    padding: 0;
}

.composer-wrapper.disabled {
    opacity: 0.6;
    pointer-events: none;
}

.composer-wrapper.disabled textarea {
    cursor: not-allowed;
}

.composer-hint {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--soft-text-secondary);
    margin-top: 0.75rem;
    padding: 0.5rem;
    background: var(--soft-bg-body);
    border-radius: 0.5rem;
}

.composer-hint i {
    color: var(--soft-warning);
}

.loading-spinner {
    width: 2rem;
    height: 2rem;
    border: 3px solid var(--soft-border);
    border-top-color: var(--soft-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ============================================
   CUSTOM SCROLLBAR - Soft UI Style
   ============================================ */
.inbox-conversations::-webkit-scrollbar,
.chat-messages::-webkit-scrollbar,
.inbox-detail::-webkit-scrollbar {
    width: 5px;
}

.inbox-conversations::-webkit-scrollbar-track,
.chat-messages::-webkit-scrollbar-track,
.inbox-detail::-webkit-scrollbar-track {
    background: transparent;
}

.inbox-conversations::-webkit-scrollbar-thumb,
.chat-messages::-webkit-scrollbar-thumb,
.inbox-detail::-webkit-scrollbar-thumb {
    background: var(--soft-border);
    border-radius: 2.5px;
}

.inbox-conversations::-webkit-scrollbar-thumb:hover,
.chat-messages::-webkit-scrollbar-thumb:hover,
.inbox-detail::-webkit-scrollbar-thumb:hover {
    background: var(--soft-text-secondary);
}

/* ============================================
   RESPONSIVE DESIGN
   ============================================ */
@media (max-width: 1400px) {
    .inbox-detail {
        width: 300px;
        min-width: 300px;
    }
}

@media (max-width: 1200px) {
    .inbox-detail {
        display: none;
    }
    
    .inbox-list {
        width: 320px;
        min-width: 320px;
    }
}

@media (max-width: 900px) {
    .inbox-list {
        width: 280px;
        min-width: 280px;
    }
    
    .conversation-item {
        margin: 4px 8px;
        padding: 12px;
    }
}

@media (max-width: 768px) {
    .inbox-container {
        height: calc(100vh - 100px);
        border-radius: 0;
        box-shadow: none;
        border: none;
    }
    
    .inbox-list {
        width: 100%;
        min-width: auto;
    }
    
    .inbox-chat {
        display: none;
    }
    
    .inbox-chat.active {
        display: flex;
        position: fixed;
        inset: 0;
        z-index: 1000;
        border-radius: 0;
    }
    
    .conversation-item {
        margin: 4px 12px;
    }
}

/* ============================================
   TEMPLATE MODAL STYLES
   ============================================ */
.template-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.template-modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(52, 71, 103, 0.6);
    backdrop-filter: blur(4px);
}

.template-modal-content {
    position: relative;
    width: 90%;
    max-width: 500px;
    max-height: 80vh;
    background: var(--soft-bg-white);
    border-radius: 1rem;
    box-shadow: var(--soft-shadow-xl);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalSlideIn 0.2s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.template-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--soft-border);
}

.template-modal-header h5 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--soft-text-dark);
    margin: 0;
}

.template-modal-close {
    width: 32px;
    height: 32px;
    border: none;
    background: var(--soft-bg-body);
    color: var(--soft-text-secondary);
    border-radius: 0.5rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}

.template-modal-close:hover {
    background: var(--soft-danger);
    color: #fff;
}

.template-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.template-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.template-list-item {
    padding: 1rem 1.25rem;
    background: var(--soft-bg-body);
    border: 1px solid var(--soft-border);
    border-radius: 0.75rem;
    cursor: pointer;
    transition: all 0.15s;
}

.template-list-item:hover {
    border-color: var(--soft-primary);
    box-shadow: var(--soft-shadow-sm);
    transform: translateY(-1px);
}

.template-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.template-item-name {
    font-weight: 700;
    font-size: 0.875rem;
    color: var(--soft-text-dark);
}

.template-item-category {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 0.25rem 0.5rem;
    background: var(--soft-gradient-primary);
    color: #fff;
    border-radius: 0.25rem;
}

.template-item-body {
    font-size: 0.8125rem;
    color: var(--soft-text-secondary);
    line-height: 1.5;
}

.template-loading,
.template-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
    text-align: center;
    color: var(--soft-text-secondary);
}

.template-empty i {
    font-size: 2.5rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}

.btn-create-template {
    margin-top: 1rem;
    padding: 0.5rem 1rem;
    background: var(--soft-gradient-primary);
    color: #fff;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
}

/* Template Preview Section */
.template-modal-preview {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--soft-border);
    background: var(--soft-bg-body);
}

.preview-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.preview-title {
    font-weight: 700;
    font-size: 0.875rem;
    color: var(--soft-text-dark);
}

.preview-back {
    border: none;
    background: none;
    color: var(--soft-primary);
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.preview-content {
    margin-bottom: 1rem;
}

.preview-bubble {
    background: var(--soft-bg-white);
    border-radius: 0.75rem;
    padding: 1rem;
    box-shadow: var(--soft-shadow-sm);
}

.preview-template-name {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--soft-primary);
    margin-bottom: 0.5rem;
}

.preview-message {
    font-size: 0.875rem;
    color: var(--soft-text-dark);
    line-height: 1.6;
    white-space: pre-wrap;
}

.preview-note {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--soft-text-secondary);
    margin-top: 0.75rem;
}

.preview-note i {
    color: var(--soft-warning);
}

.preview-actions {
    display: flex;
    gap: 0.75rem;
}

.btn-preview-edit,
.btn-preview-send {
    flex: 1;
    padding: 0.75rem 1rem;
    border: none;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.15s;
}

.btn-preview-edit {
    background: var(--soft-bg-white);
    color: var(--soft-text-dark);
    border: 1px solid var(--soft-border);
}

.btn-preview-edit:hover {
    background: var(--soft-border);
}

.btn-preview-send {
    background: var(--soft-gradient-primary);
    color: #fff;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.btn-preview-send:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5);
}

.btn-preview-send:disabled {
    opacity: 0.7;
    transform: none;
}

/* Toast Notifications */
.inbox-toast {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10000;
    padding: 0.875rem 1.5rem;
    background: var(--soft-text-dark);
    color: #fff;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.625rem;
    box-shadow: var(--soft-shadow-lg);
    animation: toastSlideIn 0.3s ease-out;
}

.inbox-toast.toast-success {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
}

.inbox-toast.toast-error {
    background: linear-gradient(310deg, #ea0606 0%, #ff667c 100%);
}

.inbox-toast.toast-hide {
    animation: toastSlideOut 0.3s ease-out forwards;
}

@keyframes toastSlideIn {
    from {
        opacity: 0;
        transform: translateX(-50%) translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
}

@keyframes toastSlideOut {
    from {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    to {
        opacity: 0;
        transform: translateX(-50%) translateY(20px);
    }
}
</style>
@endpush

@section('content')
<div class="inbox-container" id="inboxApp">
    {{-- Left Panel - Conversation List --}}
    @include('inbox._list')
    
    {{-- Center Panel - Chat Window --}}
    @include('inbox._chat')
    
    {{-- Right Panel - Detail --}}
    @include('inbox._detail')
</div>
@endsection

@push('dashboard')
<script>
/**
 * Talkabiz Inbox Module
 * WhatsApp-like chat interface
 */
const TalkabizInbox = {
    // State
    state: {
        conversations: [],
        activeConversation: null,
        messages: [],
        filter: 'all',
        search: '',
        loading: false,
        sending: false,
        userId: {{ auth()->id() ?? 'null' }}
    },
    
    // Elements
    el: {
        list: null,
        chat: null,
        detail: null,
        composer: null,
        messagesContainer: null
    },
    
    // Initialize
    init() {
        this.el.list = document.getElementById('conversationList');
        this.el.chat = document.getElementById('chatWindow');
        this.el.detail = document.getElementById('detailPanel');
        this.el.composer = document.getElementById('chatComposer');
        this.el.messagesContainer = document.getElementById('chatMessages');
        
        this.bindEvents();
        this.loadConversations();
        
        // Auto refresh setiap 30 detik
        setInterval(() => this.loadConversations(true), 30000);
    },
    
    // Bind events
    bindEvents() {
        // Search
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let timeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.state.search = e.target.value;
                    this.loadConversations();
                }, 300);
            });
        }
        
        // Filter buttons
        document.querySelectorAll('.inbox-filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.inbox-filter-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.state.filter = e.target.dataset.filter;
                this.loadConversations();
            });
        });
        
        // Message input auto-resize
        const textarea = document.getElementById('messageInput');
        if (textarea) {
            textarea.addEventListener('input', () => {
                textarea.style.height = 'auto';
                textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
            });
            
            // Enter to send (Shift+Enter for new line)
            textarea.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }
    },
    
    // Get API headers
    getHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        };
    },
    
    // Load conversations
    async loadConversations(silent = false) {
        if (!silent) {
            this.state.loading = true;
            this.renderConversationList();
        }
        
        try {
            let url = '/api/inbox?per_page=50';
            
            if (this.state.filter === 'unassigned') {
                url += '&ditangani_oleh=unassigned';
            } else if (this.state.filter === 'mine') {
                url += '&ditangani_oleh=me';
            } else if (this.state.filter === 'done') {
                url += '&status=selesai';
            }
            
            if (this.state.search) {
                url += '&search=' + encodeURIComponent(this.state.search);
            }
            
            const response = await fetch(url, { headers: this.getHeaders() });
            const data = await response.json();
            
            if (data.sukses) {
                this.state.conversations = data.data.data || [];
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
        } finally {
            this.state.loading = false;
            this.renderConversationList();
        }
    },
    
    // Render conversation list
    renderConversationList() {
        if (!this.el.list) return;
        
        if (this.state.loading) {
            this.el.list.innerHTML = `
                <div class="inbox-empty-state">
                    <div class="loading-spinner"></div>
                    <p class="mt-3">Memuat percakapan...</p>
                </div>
            `;
            return;
        }
        
        if (this.state.conversations.length === 0) {
            this.el.list.innerHTML = `
                <div class="inbox-empty-state">
                    <i class="far fa-comment-dots"></i>
                    <p>Belum ada percakapan</p>
                </div>
            `;
            return;
        }
        
        this.el.list.innerHTML = this.state.conversations.map(conv => this.renderConversationItem(conv)).join('');
        
        // Bind click events
        this.el.list.querySelectorAll('.conversation-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                this.selectConversation(id);
            });
        });
    },
    
    // Render single conversation item
    renderConversationItem(conv) {
        const isActive = this.state.activeConversation?.id == conv.id;
        const avatarColor = this.getAvatarColor(conv.nama_customer || conv.no_whatsapp);
        const initials = this.getInitials(conv.nama_customer || conv.no_whatsapp);
        const timeAgo = this.formatTimeAgo(conv.waktu_pesan_terakhir);
        
        let statusBadge = '';
        if (conv.jumlah_belum_dibaca > 0) {
            statusBadge = `<span class="conversation-badge">${conv.jumlah_belum_dibaca}</span>`;
        }
        
        let statusIndicator = '';
        if (!conv.ditangani_oleh) {
            statusIndicator = '<span class="status-indicator status-baru" title="Belum diambil"></span>';
        } else if (conv.ditangani_oleh !== this.state.userId) {
            statusIndicator = '<span class="status-indicator status-locked" title="Ditangani sales lain"></span>';
        } else {
            statusIndicator = '<span class="status-indicator status-aktif" title="Anda yang menangani"></span>';
        }
        
        const hasUnread = conv.jumlah_belum_dibaca > 0;
        
        return `
            <div class="conversation-item ${isActive ? 'active' : ''} ${hasUnread ? 'unread' : ''}" data-id="${conv.id}">
                <div class="conversation-avatar" style="background: ${avatarColor}">
                    ${initials}
                    ${statusIndicator}
                </div>
                <div class="conversation-content">
                    <div class="conversation-header">
                        <span class="conversation-name">${this.escapeHtml(conv.nama_customer || conv.no_whatsapp)}</span>
                        <span class="conversation-time">${timeAgo}</span>
                    </div>
                    <div class="conversation-footer">
                        <span class="conversation-preview">${this.escapeHtml(conv.pesan_terakhir || 'Tidak ada pesan')}</span>
                        ${statusBadge}
                    </div>
                </div>
            </div>
        `;
    },
    
    // Select conversation
    async selectConversation(id) {
        // Update active state
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.classList.toggle('active', item.dataset.id == id);
        });
        
        // Load conversation detail
        try {
            const response = await fetch(`/api/inbox/${id}`, { headers: this.getHeaders() });
            const data = await response.json();
            
            if (data.sukses) {
                this.state.activeConversation = data.data.percakapan;
                this.state.messages = (data.data.pesan.data || []).reverse();
                
                this.renderChatWindow();
                this.renderDetailPanel();
                this.scrollToBottom();
                
                // Mark as read
                this.markAsRead(id);
            }
        } catch (error) {
            console.error('Error loading conversation:', error);
        }
    },
    
    // Render chat window
    renderChatWindow() {
        const conv = this.state.activeConversation;
        if (!conv) {
            document.getElementById('chatHeader').innerHTML = '';
            document.getElementById('chatMessages').innerHTML = `
                <div class="chat-empty">
                    <i class="far fa-comments"></i>
                    <p>Pilih percakapan untuk memulai</p>
                </div>
            `;
            document.getElementById('chatComposerArea').innerHTML = '';
            return;
        }
        
        const avatarColor = this.getAvatarColor(conv.nama_customer || conv.no_whatsapp);
        const initials = this.getInitials(conv.nama_customer || conv.no_whatsapp);
        const canReply = conv.ditangani_oleh === this.state.userId;
        const isUnassigned = !conv.ditangani_oleh;
        
        // Header
        document.getElementById('chatHeader').innerHTML = `
            <div class="chat-header-info">
                <div class="chat-header-avatar" style="background: ${avatarColor}">${initials}</div>
                <div class="chat-header-details">
                    <div class="chat-header-name">${this.escapeHtml(conv.nama_customer || 'Tanpa Nama')}</div>
                    <div class="chat-header-status">${this.escapeHtml(conv.no_whatsapp)}</div>
                </div>
            </div>
            <div class="chat-header-actions">
                ${isUnassigned ? `<button class="btn-action btn-primary-action" onclick="TalkabizInbox.ambilPercakapan(${conv.id})">
                    <i class="fas fa-hand-paper"></i> Ambil
                </button>` : ''}
            </div>
        `;
        
        // Messages
        this.renderMessages();
        
        // Composer
        if (canReply) {
            document.getElementById('chatComposerArea').innerHTML = `
                <div class="composer-wrapper">
                    <button class="composer-btn btn-template" onclick="TalkabizInbox.openTemplates()" title="Pilih Template">
                        <i class="far fa-file-alt"></i>
                    </button>
                    <div class="composer-input">
                        <textarea id="messageInput" placeholder="Ketik pesan..." rows="1"></textarea>
                    </div>
                    <button class="composer-btn btn-send" onclick="TalkabizInbox.sendMessage()" id="btnSend">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            `;
            // Re-bind textarea events
            const textarea = document.getElementById('messageInput');
            if (textarea) {
                textarea.addEventListener('input', () => {
                    textarea.style.height = 'auto';
                    textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
                });
                textarea.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
            }
        } else if (isUnassigned) {
            document.getElementById('chatComposerArea').innerHTML = `
                <div class="composer-notice">
                    <i class="fas fa-info-circle me-2"></i>
                    Ambil percakapan ini untuk mulai membalas
                </div>
            `;
        } else {
            document.getElementById('chatComposerArea').innerHTML = `
                <div class="composer-notice">
                    <i class="fas fa-lock me-2"></i>
                    Percakapan ini sedang ditangani oleh sales lain
                </div>
            `;
        }
    },
    
    // Render messages
    renderMessages() {
        const container = document.getElementById('chatMessages');
        if (this.state.messages.length === 0) {
            container.innerHTML = `
                <div class="chat-empty">
                    <i class="far fa-comment-dots"></i>
                    <p>Belum ada pesan</p>
                </div>
            `;
            return;
        }
        
        let html = '';
        let lastDate = '';
        
        this.state.messages.forEach(msg => {
            const msgDate = new Date(msg.waktu_pesan).toLocaleDateString('id-ID');
            if (msgDate !== lastDate) {
                html += `<div class="message-date-divider"><span>${this.formatDate(msg.waktu_pesan)}</span></div>`;
                lastDate = msgDate;
            }
            
            html += this.renderMessageBubble(msg);
        });
        
        container.innerHTML = html;
    },
    
    // Render single message bubble
    renderMessageBubble(msg) {
        const isOutgoing = msg.arah === 'keluar';
        const time = this.formatTime(msg.waktu_pesan);
        
        let statusIcon = '';
        if (isOutgoing) {
            switch (msg.status_pengiriman) {
                case 'terkirim':
                    statusIcon = '<i class="fas fa-check status-sent"></i>';
                    break;
                case 'diterima':
                    statusIcon = '<i class="fas fa-check-double status-delivered"></i>';
                    break;
                case 'dibaca':
                    statusIcon = '<i class="fas fa-check-double status-read"></i>';
                    break;
                case 'gagal':
                    statusIcon = '<i class="fas fa-times status-failed"></i>';
                    break;
                default:
                    statusIcon = '<i class="far fa-clock status-sent"></i>';
            }
        }
        
        return `
            <div class="message-bubble ${isOutgoing ? 'outgoing' : 'incoming'}" style="display: flex;">
                <div class="message-content">${this.escapeHtml(msg.isi_pesan || '')}</div>
                <div class="message-meta">
                    <span>${time}</span>
                    ${statusIcon}
                </div>
            </div>
        `;
    },
    
    // Render detail panel
    renderDetailPanel() {
        const conv = this.state.activeConversation;
        if (!conv) {
            document.getElementById('detailContent').innerHTML = `
                <div class="inbox-empty-state">
                    <i class="far fa-address-card"></i>
                    <p>Pilih percakapan</p>
                </div>
            `;
            return;
        }
        
        const avatarColor = this.getAvatarColor(conv.nama_customer || conv.no_whatsapp);
        const initials = this.getInitials(conv.nama_customer || conv.no_whatsapp);
        const canManage = conv.ditangani_oleh === this.state.userId;
        const isUnassigned = !conv.ditangani_oleh;
        
        document.getElementById('detailContent').innerHTML = `
            <!-- Contact Header -->
            <div class="detail-header">
                <div class="detail-avatar" style="background: ${avatarColor}">${initials}</div>
                <div class="detail-name">${this.escapeHtml(conv.nama_customer || 'Tanpa Nama')}</div>
                <div class="detail-phone">${this.escapeHtml(conv.no_whatsapp)}</div>
                <div class="detail-tags">
                    <span class="detail-tag">${conv.sumber || 'Inbound'}</span>
                    <span class="detail-tag">${conv.status || 'aktif'}</span>
                </div>
            </div>
            
            <!-- Info Section -->
            <div class="detail-section">
                <div class="detail-section-title">Informasi</div>
                <div class="detail-row">
                    <span class="detail-label">Status</span>
                    <span class="detail-value">${conv.status || '-'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Prioritas</span>
                    <span class="detail-value">${conv.prioritas || 'normal'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Ditangani</span>
                    <span class="detail-value">${conv.penanggungjawab?.nama || conv.penanggungjawab?.email || 'Belum ada'}</span>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="detail-section">
                <div class="detail-section-title">Aksi</div>
                <div class="detail-actions">
                    ${isUnassigned ? `
                        <button class="detail-btn detail-btn-primary" onclick="TalkabizInbox.ambilPercakapan(${conv.id})">
                            <i class="fas fa-hand-paper"></i> Ambil Percakapan
                        </button>
                    ` : ''}
                    ${canManage ? `
                        <button class="detail-btn detail-btn-primary" onclick="TalkabizInbox.selesaikanPercakapan(${conv.id})">
                            <i class="fas fa-check"></i> Tandai Selesai
                        </button>
                        <button class="detail-btn detail-btn-secondary" onclick="TalkabizInbox.lepasPercakapan(${conv.id})">
                            <i class="fas fa-sign-out-alt"></i> Lepas
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    },
    
    // Ambil percakapan
    async ambilPercakapan(id) {
        try {
            const response = await fetch(`/api/inbox/${id}/ambil`, {
                method: 'POST',
                headers: this.getHeaders()
            });
            const data = await response.json();
            
            if (data.sukses) {
                this.loadConversations();
                this.selectConversation(id);
            } else {
                alert(data.pesan || 'Gagal mengambil percakapan');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Terjadi kesalahan');
        }
    },
    
    // Lepas percakapan
    async lepasPercakapan(id) {
        if (!confirm('Yakin ingin melepas percakapan ini?')) return;
        
        try {
            const response = await fetch(`/api/inbox/${id}/lepas`, {
                method: 'POST',
                headers: this.getHeaders()
            });
            const data = await response.json();
            
            if (data.sukses) {
                this.loadConversations();
                this.selectConversation(id);
            } else {
                alert(data.pesan || 'Gagal melepas percakapan');
            }
        } catch (error) {
            console.error('Error:', error);
        }
    },
    
    // Selesaikan percakapan
    async selesaikanPercakapan(id) {
        if (!confirm('Tandai percakapan ini sebagai selesai?')) return;
        
        try {
            const response = await fetch(`/api/inbox/${id}/selesai`, {
                method: 'POST',
                headers: this.getHeaders()
            });
            const data = await response.json();
            
            if (data.sukses) {
                this.loadConversations();
                this.state.activeConversation = null;
                this.renderChatWindow();
                this.renderDetailPanel();
            } else {
                alert(data.pesan || 'Gagal menyelesaikan percakapan');
            }
        } catch (error) {
            console.error('Error:', error);
        }
    },
    
    // Mark as read
    async markAsRead(id) {
        try {
            await fetch(`/api/inbox/${id}/baca`, {
                method: 'POST',
                headers: this.getHeaders()
            });
        } catch (error) {
            console.error('Error marking as read:', error);
        }
    },
    
    // Send message
    async sendMessage() {
        const textarea = document.getElementById('messageInput');
        const message = textarea?.value?.trim();
        
        if (!message || !this.state.activeConversation) return;
        if (this.state.sending) return;
        
        // === LIMIT CHECK: Check quota before sending ===
        if (typeof LimitMonitor !== 'undefined') {
            const limitCheck = await LimitMonitor.checkAndWarn(1);
            if (!limitCheck.canProceed) {
                return; // LimitMonitor already showed popup
            }
        }
        
        this.state.sending = true;
        const btnSend = document.getElementById('btnSend');
        if (btnSend) {
            btnSend.disabled = true;
            btnSend.innerHTML = '<div class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></div>';
        }
        
        try {
            const response = await fetch(`/api/inbox/${this.state.activeConversation.id}/kirim`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({
                    tipe: 'teks',
                    isi_pesan: message
                })
            });
            const data = await response.json();
            
            if (data.sukses) {
                textarea.value = '';
                textarea.style.height = 'auto';
                
                // Add message to local state
                if (data.data?.pesan) {
                    this.state.messages.push(data.data.pesan);
                    this.renderMessages();
                    this.scrollToBottom();
                } else {
                    // Reload conversation
                    this.selectConversation(this.state.activeConversation.id);
                }
                
                // Refresh quota cache after successful send
                if (typeof LimitMonitor !== 'undefined') {
                    LimitMonitor.refreshQuota();
                }
            } else {
                if (typeof ClientPopup !== 'undefined') {
                    ClientPopup.actionFailed(data.pesan || 'Pesan belum bisa dikirim. Coba lagi.');
                } else {
                    alert(data.pesan || 'Gagal mengirim pesan');
                }
            }
        } catch (error) {
            console.error('Error sending message:', error);
            if (typeof ClientPopup !== 'undefined') {
                ClientPopup.connectionError();
            } else {
                alert('Gagal mengirim pesan');
            }
        } finally {
            this.state.sending = false;
            if (btnSend) {
                btnSend.disabled = false;
                btnSend.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        }
    },
    
    // Open templates (placeholder)
    openTemplates() {
        this.showTemplateModal();
    },

    // Template Modal Functions
    async showTemplateModal() {
        // Create modal if not exists
        if (!document.getElementById('templateModal')) {
            this.createTemplateModal();
        }
        
        const modal = document.getElementById('templateModal');
        const listContainer = document.getElementById('templateList');
        
        // Show modal
        modal.style.display = 'flex';
        listContainer.innerHTML = '<div class="template-loading"><div class="loading-spinner"></div><p>Memuat template...</p></div>';
        
        // Fetch templates
        try {
            const response = await fetch('/api/templates/active', { headers: this.getHeaders() });
            const data = await response.json();
            
            if (data.sukses && data.data.length > 0) {
                listContainer.innerHTML = data.data.map(t => this.renderTemplateItem(t)).join('');
                
                // Bind click events
                listContainer.querySelectorAll('.template-list-item').forEach(item => {
                    item.addEventListener('click', () => {
                        const templateId = item.dataset.id;
                        const template = data.data.find(t => t.id == templateId);
                        if (template) {
                            this.selectTemplate(template);
                        }
                    });
                });
            } else {
                listContainer.innerHTML = `
                    <div class="template-empty">
                        <i class="ni ni-single-copy-04"></i>
                        <p>Belum ada template</p>
                        <a href="/template" class="btn-create-template">Buat Template</a>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading templates:', error);
            listContainer.innerHTML = '<div class="template-empty"><p>Gagal memuat template</p></div>';
        }
    },

    createTemplateModal() {
        const modalHtml = `
            <div class="template-modal" id="templateModal">
                <div class="template-modal-overlay" onclick="TalkabizInbox.closeTemplateModal()"></div>
                <div class="template-modal-content">
                    <div class="template-modal-header">
                        <h5>Pilih Template Pesan</h5>
                        <button class="template-modal-close" onclick="TalkabizInbox.closeTemplateModal()">
                            <i class="ni ni-fat-remove"></i>
                        </button>
                    </div>
                    <div class="template-modal-body">
                        <div id="templateList" class="template-list"></div>
                    </div>
                    <div class="template-modal-preview" id="templatePreviewSection" style="display: none;">
                        <div class="preview-header">
                            <span class="preview-title">Preview Pesan</span>
                            <button class="preview-back" onclick="TalkabizInbox.backToTemplateList()">
                                <i class="ni ni-bold-left"></i> Kembali
                            </button>
                        </div>
                        <div class="preview-content" id="templatePreviewContent"></div>
                        <div class="preview-actions">
                            <button class="btn-preview-edit" onclick="TalkabizInbox.editBeforeSend()">
                                <i class="ni ni-ruler-pencil"></i> Edit
                            </button>
                            <button class="btn-preview-send" onclick="TalkabizInbox.sendTemplateMessage()">
                                <i class="ni ni-send"></i> Kirim Sekarang
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    renderTemplateItem(template) {
        const categoryLabels = {
            'marketing': 'Marketing',
            'utility': 'Utility',
            'authentication': 'Auth',
            'transactional': 'Transaksi',
            'notification': 'Notifikasi',
            'greeting': 'Sapaan',
            'follow_up': 'Follow Up',
            'other': 'Lainnya'
        };
        
        return `
            <div class="template-list-item" data-id="${template.id}">
                <div class="template-item-header">
                    <span class="template-item-name">${this.escapeHtml(template.nama)}</span>
                    <span class="template-item-category">${categoryLabels[template.kategori] || template.kategori}</span>
                </div>
                <div class="template-item-body">${this.escapeHtml(template.isi_body || '').substring(0, 100)}...</div>
            </div>
        `;
    },

    selectedTemplate: null,
    renderedMessage: '',

    selectTemplate(template) {
        this.selectedTemplate = template;
        
        // Get contact data from active conversation
        const conv = this.state.activeConversation;
        const contactData = {
            nama: conv?.nama_customer || '',
            telepon: conv?.no_whatsapp || '',
            email: '',
            produk: '',
            harga: '',
            tanggal: new Date().toLocaleDateString('id-ID'),
            no_order: ''
        };
        
        // Render template with variables
        this.renderedMessage = this.renderTemplateLocally(template.isi_body, contactData);
        
        // Show preview
        document.getElementById('templateList').style.display = 'none';
        document.getElementById('templatePreviewSection').style.display = 'block';
        document.getElementById('templatePreviewContent').innerHTML = `
            <div class="preview-bubble">
                <div class="preview-template-name">${this.escapeHtml(template.nama)}</div>
                <div class="preview-message">${this.escapeHtml(this.renderedMessage)}</div>
            </div>
            <div class="preview-note">
                <i class="ni ni-bulb-61"></i>
                Variable yang kosong akan dihilangkan otomatis
            </div>
        `;
    },

    renderTemplateLocally(template, data) {
        let rendered = template || '';
        const variables = ['nama', 'telepon', 'email', 'produk', 'harga', 'tanggal', 'no_order'];
        
        variables.forEach(v => {
            const value = data[v] || '';
            rendered = rendered.replace(new RegExp('\\{\\{\\s*' + v + '\\s*\\}\\}', 'g'), value);
        });
        
        return rendered;
    },

    backToTemplateList() {
        document.getElementById('templateList').style.display = 'block';
        document.getElementById('templatePreviewSection').style.display = 'none';
        this.selectedTemplate = null;
        this.renderedMessage = '';
    },

    closeTemplateModal() {
        const modal = document.getElementById('templateModal');
        if (modal) {
            modal.style.display = 'none';
            this.backToTemplateList();
        }
    },

    editBeforeSend() {
        // Close modal and inject to textarea
        this.closeTemplateModal();
        
        const textarea = document.getElementById('messageInput');
        if (textarea && this.renderedMessage) {
            textarea.value = this.renderedMessage;
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
            textarea.focus();
        }
    },

    async sendTemplateMessage() {
        if (!this.selectedTemplate || !this.state.activeConversation) {
            this.showToast('Pilih template dan percakapan terlebih dahulu', 'error');
            return;
        }
        
        if (!this.renderedMessage.trim()) {
            this.showToast('Pesan tidak boleh kosong', 'error');
            return;
        }
        
        // === LIMIT CHECK: Check quota before sending ===
        if (typeof LimitMonitor !== 'undefined') {
            const limitCheck = await LimitMonitor.checkAndWarn(1);
            if (!limitCheck.canProceed) {
                return; // LimitMonitor already showed popup
            }
        }
        
        const sendBtn = document.querySelector('.btn-preview-send');
        if (sendBtn) {
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<div class="loading-spinner" style="width:16px;height:16px;border-width:2px;"></div> Mengirim...';
        }
        
        try {
            const response = await fetch(`/api/inbox/${this.state.activeConversation.id}/send-template`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({
                    template_id: this.selectedTemplate.id,
                    rendered_message: this.renderedMessage,
                    raw_template: this.selectedTemplate.isi_body,
                    contact_id: null
                })
            });
            
            const data = await response.json();
            
            if (data.sukses) {
                this.closeTemplateModal();
                
                // Add message to local state
                if (data.data?.pesan) {
                    this.state.messages.push(data.data.pesan);
                    this.renderMessages();
                    this.scrollToBottom();
                }
                
                // Show balance info if available - using friendly toast
                if (data.data?.billing) {
                    this.showToast(`Pesan terkirim! Saldo: Rp ${data.data.billing.saldo_sesudah.toLocaleString('id-ID')}`, 'success');
                } else {
                    this.showToast('Pesan berhasil dikirim', 'success');
                }
                
                // Refresh quota cache after successful send
                if (typeof LimitMonitor !== 'undefined') {
                    LimitMonitor.refreshQuota();
                }
                
                // Update wallet display if exists
                this.updateWalletDisplay(data.data?.billing);
                
                // Reload conversation list
                this.loadConversations(true);
            } else {
                // Handle insufficient balance/quota using friendly popup
                if (data.error_code === 'INSUFFICIENT_BALANCE' || data.error_code === 'QUOTA_EXCEEDED') {
                    if (typeof ClientPopup !== 'undefined') {
                        ClientPopup.limitExhausted('/billing/plan');
                    } else {
                        this.showToast(data.pesan, 'error');
                        if (confirm('Kuota/saldo tidak mencukupi. Buka halaman upgrade?')) {
                            window.location.href = '/billing/plan';
                        }
                    }
                } else {
                    if (typeof ClientPopup !== 'undefined') {
                        ClientPopup.actionFailed(data.pesan || 'Pesan belum bisa dikirim. Coba lagi.');
                    } else {
                        this.showToast(data.pesan || 'Gagal mengirim pesan', 'error');
                    }
                }
            }
        } catch (error) {
            console.error('Error sending template:', error);
            if (typeof ClientPopup !== 'undefined') {
                ClientPopup.connectionError();
            } else {
                this.showToast('Terjadi kesalahan saat mengirim', 'error');
            }
        } finally {
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="ni ni-send"></i> Kirim Sekarang';
            }
        }
    },

    showToast(message, type = 'info') {
        // Remove existing toasts
        document.querySelectorAll('.inbox-toast').forEach(t => t.remove());
        
        const toast = document.createElement('div');
        toast.className = `inbox-toast toast-${type}`;
        toast.innerHTML = `
            <i class="ni ni-${type === 'success' ? 'check-bold' : type === 'error' ? 'fat-remove' : 'bell-55'}"></i>
            <span>${message}</span>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },
    
    // Update wallet display in sidebar (if exists)
    updateWalletDisplay(billing) {
        if (!billing) return;
        
        // Update sidebar saldo widget if exists
        const saldoElement = document.querySelector('.wallet-saldo-display');
        if (saldoElement) {
            saldoElement.textContent = `Rp ${billing.saldo_sesudah.toLocaleString('id-ID')}`;
        }
    },
    
    // Scroll to bottom
    scrollToBottom() {
        const container = document.getElementById('chatMessages');
        if (container) {
            setTimeout(() => {
                container.scrollTop = container.scrollHeight;
            }, 100);
        }
    },
    
    // Utility: Get avatar color (Flat colors - WhatsApp style)
    getAvatarColor(name) {
        const colors = [
            '#00a884',  // WhatsApp green
            '#5f66cd',  // Purple
            '#f27b60',  // Coral
            '#54bcb1',  // Teal
            '#8e8ee5',  // Lavender
            '#f49935',  // Orange
            '#6bb9f0',  // Sky blue
            '#e85d75'   // Pink
        ];
        const hash = (name || 'X').split('').reduce((a, b) => a + b.charCodeAt(0), 0);
        return colors[hash % colors.length];
    },
    
    // Utility: Get initials
    getInitials(name) {
        if (!name) return '?';
        const parts = name.replace(/[^a-zA-Z0-9 ]/g, '').split(' ').filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return (name.substring(0, 2)).toUpperCase();
    },
    
    // Utility: Format time ago
    formatTimeAgo(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        const mins = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);
        
        if (mins < 1) return 'Baru saja';
        if (mins < 60) return `${mins}m`;
        if (hours < 24) return `${hours}j`;
        if (days < 7) return `${days}h`;
        return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
    },
    
    // Utility: Format date
    formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        if (date.toDateString() === today.toDateString()) return 'Hari ini';
        if (date.toDateString() === yesterday.toDateString()) return 'Kemarin';
        return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    },
    
    // Utility: Format time
    formatTime(dateStr) {
        if (!dateStr) return '';
        return new Date(dateStr).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    },
    
    // Utility: Escape HTML
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    TalkabizInbox.init();
});
</script>
@endpush
