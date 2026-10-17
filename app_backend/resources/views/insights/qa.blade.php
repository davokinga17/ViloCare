@extends('layouts.app')

@section('title', 'AI Staff Q&A Assistant')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">🤖 Staff Clinical Q&A Assistant</h1>
        <p class="page-subtitle">
            Ask natural-language questions about clinic-level performance &bull; <strong>100% Anonymized Aggregated Data</strong>
        </p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" onclick="inspectZeroPiiPayload()">
            <span>🔒</span> Privacy Audit (Inspect Payload)
        </button>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary">
            <span>&larr;</span> Back to Dashboard
        </a>
    </div>
</div>

@include('partials.integration-notice', ['kind' => 'gemini'])

<!-- Aggregated Cohort Snapshot Ribbon -->
<div class="dashboard-ribbon-grid" style="margin-bottom: 20px;">
    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px;">
        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Active ART Cohort</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #0f172a; margin-top: 2px;">
            {{ $aggregatedData['cohort_demographics']['total_active_patients'] }} Patients
        </div>
        <div style="font-size: 0.75rem; color: #64748b;">
            Kids: {{ $aggregatedData['cohort_demographics']['age_breakdown']['under_15_pediatric'] }} &bull; Adults: {{ $aggregatedData['cohort_demographics']['age_breakdown']['age_25_plus_adults'] }}
        </div>
    </div>

    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px;">
        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Suppression Rate (&lt;1k)</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #059669; margin-top: 2px;">
            {{ $aggregatedData['viral_load_metrics']['overall_suppression_rate_percent'] }}%
        </div>
        <div style="font-size: 0.75rem; color: #64748b;">
            Pediatric: {{ $aggregatedData['viral_load_metrics']['pediatric_suppression_rate_percent'] }}% &bull; Adults: {{ $aggregatedData['viral_load_metrics']['adult_suppression_rate_percent'] }}%
        </div>
    </div>

    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px;">
        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Active EAC Cascade</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #d97706; margin-top: 2px;">
            {{ $aggregatedData['eac_cascade']['active_counselling_episodes'] }} Active Episodes
        </div>
        <div style="font-size: 0.75rem; color: #64748b;">
            {{ $aggregatedData['eac_cascade']['completed_sessions_awaiting_repeat_vl'] }} completed 3 sessions
        </div>
    </div>

    <div style="background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 12px 16px;">
        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Due / Overdue VL</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #2563eb; margin-top: 2px;">
            {{ $aggregatedData['appointments_and_due_monitoring']['patients_due_for_routine_vl_next_30_days'] }} Due in 30d
        </div>
        <div style="font-size: 0.75rem; color: #dc2626; font-weight: 600;">
            {{ $aggregatedData['appointments_and_due_monitoring']['patients_overdue_for_vl_testing'] }} currently overdue
        </div>
    </div>
</div>

<!-- Main Q&A Assistant Container -->
<div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
    <!-- Chat Card -->
    <div class="card" style="display: flex; flex-direction: column; min-height: 600px; box-shadow: var(--shadow-md);">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #ffffff; border-bottom: 1px solid var(--border); padding: 14px 20px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">💬</span>
                <div>
                    <h2 class="card-title" style="margin: 0; font-size: 1.05rem; color: #0f172a;">Clinical Q&A Consultation Feed</h2>
                    <div style="font-size: 0.75rem; color: #64748b;">Answers are generated strictly from aggregated database queries</div>
                </div>
            </div>
            <div style="font-size: 0.75rem; background: #f0fdfa; color: #0f766e; padding: 4px 10px; border-radius: 9999px; border: 1px solid #ccfbf1; font-weight: 600;">
                ⚡ Rate-limited: 30 inquiries / hr per staff
            </div>
        </div>

        <!-- Preset Suggested Prompts -->
        <div style="padding: 12px 20px; background: #f8fafc; border-bottom: 1px solid var(--border);">
            <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">
                💡 Quick Inquiries (Click to ask):
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                @foreach($sampleQuestions as $prompt)
                    <button type="button" class="btn btn-sm btn-secondary" onclick="askPresetPrompt('{{ addslashes($prompt) }}')" style="font-size: 0.78rem; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 9999px; padding: 4px 12px; text-align: left;">
                        {{ $prompt }}
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Chat History Stream -->
        <div id="qa-chat-stream" style="flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; background: #fafafa; min-height: 320px;">
            <!-- Welcome System Bubble -->
            <div style="display: flex; gap: 12px; max-width: 85%;">
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #0d9488; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                    🤖
                </div>
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 14px 16px; box-shadow: var(--shadow-sm);">
                    <div style="font-size: 0.8rem; font-weight: 700; color: #0d9488; margin-bottom: 4px;">ViLoCare Clinical Assistant</div>
                    <div style="font-size: 0.88rem; line-height: 1.6; color: #334155;">
                        Hello! I am your AI clinical decision-support assistant. You can ask me questions regarding clinic cohort breakdown, viral load suppression rates, EAC counseling status, sample rejection rates, and overdue visits.
                        <br><br>
                        <em>Note: To protect confidentiality, I analyze aggregated numbers only and never receive or disclose individual patient names or records.</em>
                    </div>
                </div>
            </div>
        </div>

        <!-- Input Area -->
        <div style="padding: 16px 20px; background: #ffffff; border-top: 1px solid var(--border);">
            <form id="qa-form" onsubmit="submitQuestion(event)" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <textarea 
                        id="qa-input" 
                        rows="2" 
                        class="form-control" 
                        placeholder="Type your clinical question (e.g. 'How many pregnant women need viral load testing this month?')..." 
                        style="width: 100%; resize: none; border-radius: var(--radius-md); font-size: 0.9rem; padding: 10px 14px;"
                        required
                    ></textarea>
                </div>
                <button type="submit" id="btn-submit-qa" class="btn btn-primary" style="background: #0d9488; border-color: #0d9488; padding: 10px 20px; font-weight: 600; display: flex; align-items: center; gap: 6px; height: 46px;">
                    <span id="qa-send-icon">📤</span>
                    <span id="qa-send-text">Ask AI</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Privacy Audit Modal (Zero-PII Payload Inspector) -->
<div id="privacy-audit-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; border-radius: var(--radius-lg); width: 100%; max-width: 760px; max-height: 85vh; display: flex; flex-direction: column; box-shadow: var(--shadow-lg); border: 1px solid var(--border);">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 1.25rem;">🔒</span>
                <strong style="color: #0f172a; font-size: 1.05rem;">Privacy & Anonymization Audit</strong>
            </div>
            <button type="button" onclick="closePrivacyAuditModal()" style="border: none; background: transparent; font-size: 1.25rem; cursor: pointer; color: #64748b;">✕</button>
        </div>
        <div style="padding: 16px 20px; overflow-y: auto; flex: 1;">
            <div class="alert alert-success" style="margin-bottom: 14px; font-size: 0.85rem;">
                <span>🛡️</span>
                <div>
                    <strong>Zero-PII Guarantee:</strong> Only aggregated counts, cohort rates, and category statistics are transmitted to the AI provider. No names, ART numbers, phones, or individual records leave this server.
                </div>
            </div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 6px; text-transform: uppercase;">
                Exact Payload Structure Sent to AI Service:
            </div>
            <pre id="privacy-payload-viewer" style="background: #0f172a; color: #38bdf8; padding: 14px; border-radius: var(--radius-md); font-size: 0.78rem; overflow-x: auto; max-height: 380px; font-family: monospace;"></pre>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border); background: #f8fafc; display: flex; justify-content: flex-end; border-bottom-left-radius: var(--radius-lg); border-bottom-right-radius: var(--radius-lg);">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closePrivacyAuditModal()">Close Inspector</button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    // Lightweight markdown formatter for AI responses
    function renderMarkdown(md) {
        if (!md) return '';
        let html = md
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");

        // Headers
        html = html.replace(/^### (.*$)/gim, '<h4 style="color: #0f766e; margin-top: 10px; margin-bottom: 4px; font-size: 0.95rem; font-weight: 700;">$1</h4>');
        html = html.replace(/^## (.*$)/gim, '<h3 style="color: #0f766e; margin-top: 12px; margin-bottom: 6px; font-size: 1.05rem; font-weight: 700;">$1</h3>');
        html = html.replace(/^# (.*$)/gim, '<h2 style="color: #0f766e; margin-top: 14px; margin-bottom: 8px; font-size: 1.15rem; font-weight: 700;">$1</h2>');

        // Bold & Italic
        html = html.replace(/\*\*(.*?)\*\*/gim, '<strong style="color: #0f172a;">$1</strong>');
        html = html.replace(/\*(.*?)\*/gim, '<em>$1</em>');

        // List items
        html = html.replace(/^\s*[\-\*]\s+(.*$)/gim, '<li style="margin-bottom: 4px; margin-left: 18px;">$1</li>');
        html = html.replace(/^\s*(\d+)\.\s+(.*$)/gim, '<li style="margin-bottom: 4px; margin-left: 18px;" value="$1">$2</li>');

        // Paragraph line breaks
        html = html.replace(/\n\n+/g, '<div style="margin-bottom: 8px;"></div>');
        html = html.replace(/\n/g, '<br>');

        return html;
    }

    function askPresetPrompt(question) {
        const input = document.getElementById('qa-input');
        if (input) {
            input.value = question;
            submitQuestion(new Event('submit'));
        }
    }

    async function submitQuestion(e) {
        if (e) e.preventDefault();
        const input = document.getElementById('qa-input');
        const question = input?.value?.trim();
        if (!question) return;

        const stream = document.getElementById('qa-chat-stream');
        const sendBtn = document.getElementById('btn-submit-qa');
        const sendText = document.getElementById('qa-send-text');
        const sendIcon = document.getElementById('qa-send-icon');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // 1. Append Staff Question Bubble
        const now = new Date();
        const timeFormatted = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        const userBubble = document.createElement('div');
        userBubble.style.display = 'flex';
        userBubble.style.justifyContent = 'flex-end';
        userBubble.style.gap = '10px';
        userBubble.innerHTML = `
            <div style="background: #0f766e; color: #ffffff; border-radius: var(--radius-md); padding: 12px 16px; max-width: 80%; box-shadow: var(--shadow-sm);">
                <div style="font-size: 0.75rem; color: #ccfbf1; margin-bottom: 2px; text-align: right;">You &bull; ${timeFormatted}</div>
                <div style="font-size: 0.9rem; line-height: 1.5;">${escapeHtml(question)}</div>
            </div>
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #134e4a; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; font-weight: 700; flex-shrink: 0;">
                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
            </div>
        `;
        stream.appendChild(userBubble);
        input.value = '';

        // 2. Append Pending AI Bubble
        const aiBubbleId = 'ai-response-' + Date.now();
        const aiBubble = document.createElement('div');
        aiBubble.id = aiBubbleId;
        aiBubble.style.display = 'flex';
        aiBubble.style.gap = '12px';
        aiBubble.style.maxWidth = '85%';
        aiBubble.innerHTML = `
            <div style="width: 36px; height: 36px; border-radius: 50%; background: #0d9488; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0;">
                🤖
            </div>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: var(--radius-md); padding: 14px 16px; box-shadow: var(--shadow-sm); flex: 1;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #0d9488; margin-bottom: 4px;">ViLoCare Assistant</div>
                <div class="ai-body" style="font-size: 0.88rem; line-height: 1.6; color: #64748b;">
                    <span style="display: inline-block; animation: pulse 1s infinite;">✨ Analyzing clinic metrics...</span>
                </div>
            </div>
        `;
        stream.appendChild(aiBubble);
        stream.scrollTop = stream.scrollHeight;

        // Disable input
        if (sendBtn) sendBtn.disabled = true;
        if (sendText) sendText.innerText = 'Thinking...';
        if (sendIcon) sendIcon.innerText = '⏳';

        try {
            const resp = await fetch("{{ route('insights.qa.ask') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ question: question })
            });

            const data = await resp.json();
            const bodyEl = aiBubble.querySelector('.ai-body');

            if (data.answer) {
                bodyEl.style.color = '#334155';
                bodyEl.innerHTML = renderMarkdown(data.answer);
            } else if (data.message) {
                bodyEl.style.color = '#dc2626';
                bodyEl.innerHTML = '⚠️ ' + escapeHtml(data.message);
            } else {
                bodyEl.style.color = '#dc2626';
                bodyEl.innerHTML = '⚠️ Unexpected error processing response.';
            }
        } catch (err) {
            const bodyEl = aiBubble.querySelector('.ai-body');
            bodyEl.style.color = '#dc2626';
            bodyEl.innerHTML = '⚠️ Network or service error: ' + escapeHtml(err.message);
        } finally {
            if (sendBtn) sendBtn.disabled = false;
            if (sendText) sendText.innerText = 'Ask AI';
            if (sendIcon) sendIcon.innerText = '📤';
            stream.scrollTop = stream.scrollHeight;
        }
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Enter key submits (Shift+Enter makes newline)
    document.getElementById('qa-input')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submitQuestion(e);
        }
    });

    async function inspectZeroPiiPayload() {
        const modal = document.getElementById('privacy-audit-modal');
        const viewer = document.getElementById('privacy-payload-viewer');
        if (modal) modal.style.display = 'flex';
        if (viewer) viewer.innerText = 'Loading aggregated dataset snapshot...';

        try {
            const resp = await fetch("{{ route('insights.preview-payload') }}");
            const data = await resp.json();
            if (viewer) {
                viewer.innerText = JSON.stringify(data, null, 2);
            }
        } catch (err) {
            if (viewer) viewer.innerText = 'Error fetching payload: ' + err.message;
        }
    }

    function closePrivacyAuditModal() {
        const modal = document.getElementById('privacy-audit-modal');
        if (modal) modal.style.display = 'none';
    }
</script>
@endpush
