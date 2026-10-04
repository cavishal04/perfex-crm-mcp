(function ($) {
    'use strict';

    var cfg = window.AI_MCP_CHAT;
    if (!cfg || !$) {
        return;
    }

    var $panel = $('#ai-mcp-panel');
    var $messages = $('#ai-mcp-messages');
    var $confirm = $('#ai-mcp-confirm');
    var $text = $('#ai-mcp-text');
    var $send = $('#ai-mcp-send');
    var busy = false;
    var loaded = false;

    function store(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }
            window.localStorage.setItem(key, value);
        } catch (e) {
            return null;
        }
    }

    function csrfData(data) {
        data = data || {};
        // Prefer Perfex's live token in case it was regenerated after page load.
        if (window.csrfData && window.csrfData.token_name) {
            data[window.csrfData.token_name] = window.csrfData.hash;
        } else {
            data[cfg.csrf.name] = cfg.csrf.hash;
        }
        return data;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function safeUrl(url) {
        url = url.replace(/&amp;/g, '&');
        return /^(https?:\/\/|\/)/i.test(url) ? url : '#';
    }

    function inline(s) {
        return s
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, url) {
                return '<a href="' + escapeHtml(safeUrl(url)) + '">' + label + '</a>';
            });
    }

    // Small Markdown subset (paragraphs, bullet/numbered lists, bold, code, links).
    // Input is escaped first, so model output can never inject HTML.
    function markdown(text) {
        var lines = escapeHtml(text || '').split(/\r?\n/);
        var html = '';
        var list = null;
        var para = [];

        function flushPara() {
            if (para.length) {
                html += '<p>' + inline(para.join('<br>')) + '</p>';
                para = [];
            }
        }
        function closeList() {
            if (list) {
                html += '</' + list + '>';
                list = null;
            }
        }

        lines.forEach(function (line) {
            var bullet = line.match(/^\s*[-*•]\s+(.*)$/);
            var numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
            var heading = line.match(/^\s*#{1,6}\s+(.*)$/);
            if (bullet || numbered) {
                flushPara();
                var type = bullet ? 'ul' : 'ol';
                if (list !== type) {
                    closeList();
                    html += '<' + type + '>';
                    list = type;
                }
                html += '<li>' + inline((bullet || numbered)[1]) + '</li>';
            } else if (heading) {
                flushPara();
                closeList();
                html += '<p><strong>' + inline(heading[1]) + '</strong></p>';
            } else if (line.trim() === '') {
                flushPara();
                closeList();
            } else {
                closeList();
                para.push(line);
            }
        });
        flushPara();
        closeList();
        return html;
    }

    function scrollDown() {
        $messages.scrollTop($messages[0].scrollHeight);
    }

    function addMessage(role, text) {
        var $m = $('<div class="ai-mcp-msg"></div>').addClass('ai-mcp-msg-' + role);
        if (role === 'assistant') {
            $m.html(markdown(text));
        } else {
            $m.text(text);
        }
        $messages.append($m);
        scrollDown();
    }

    function addActions(actions) {
        if (!actions || !actions.length) {
            return;
        }
        var $wrap = $('<div class="ai-mcp-actions"></div>');
        actions.forEach(function (a) {
            var $chip = $('<span class="ai-mcp-chip"></span>')
                .text((a.ok ? '✓ ' : '✕ ') + a.tool.replace(/_/g, ' '))
                .attr('title', a.ok ? cfg.lang.done : (cfg.lang.failed + ': ' + (a.error || '')));
            if (!a.ok) {
                $chip.addClass('is-failed');
            }
            $wrap.append($chip);
        });
        $messages.append($wrap);
        scrollDown();
    }

    function setBusy(state) {
        busy = state;
        $send.prop('disabled', state);
        $text.prop('disabled', state);
        $messages.find('.ai-mcp-typing').remove();
        if (state) {
            $messages.append($('<div class="ai-mcp-typing"></div>').text(cfg.lang.thinking));
            scrollDown();
        } else {
            $text.trigger('focus');
        }
    }

    function formatValue(v) {
        if (v === null || v === undefined) {
            return '';
        }
        if (typeof v === 'object') {
            return JSON.stringify(v);
        }
        return String(v);
    }

    function showConfirm(pending) {
        if (!pending || !pending.length) {
            $confirm.attr('hidden', true).empty();
            return;
        }
        var $box = $('<div></div>');
        $box.append($('<h5></h5>').text(cfg.lang.confirmTitle));
        pending.forEach(function (p) {
            var $dl = $('<dl></dl>');
            $dl.append($('<dt></dt>').text(p.tool.replace(/_/g, ' ')));
            Object.keys(p.args || {}).forEach(function (k) {
                $dl.append($('<dd></dd>').text(k + ': ' + formatValue(p.args[k])));
            });
            $box.append($dl);
        });
        var $buttons = $('<div class="ai-mcp-confirm-buttons"></div>');
        $buttons.append($('<button type="button" class="btn btn-primary btn-sm"></button>').text(cfg.lang.approve).on('click', function () { answer(true); }));
        $buttons.append($('<button type="button" class="btn btn-default btn-sm"></button>').text(cfg.lang.cancel).on('click', function () { answer(false); }));
        $box.append($buttons);
        $confirm.empty().append($box).removeAttr('hidden');
        scrollDown();
    }

    function handle(res) {
        if (typeof res === 'string') {
            try {
                res = JSON.parse(res);
            } catch (e) {
                res = { error: cfg.lang.error };
            }
        }
        if (res.notice) {
            addMessage('system', res.notice);
        }
        addActions(res.actions);
        if (res.reply) {
            addMessage('assistant', res.reply);
        }
        if (res.error) {
            addMessage('error', res.error);
        }
        showConfirm(res.confirm);
    }

    function post(url, data) {
        setBusy(true);
        return $.ajax({ url: url, type: 'POST', data: csrfData(data), dataType: 'json' })
            .done(handle)
            .fail(function () { addMessage('error', cfg.lang.error); })
            .always(function () { setBusy(false); });
    }

    function answer(approve) {
        showConfirm(null);
        post(cfg.urls.confirm, { approve: approve ? 1 : 0 });
    }

    function send() {
        var text = $.trim($text.val());
        if (!text || busy) {
            return;
        }
        $text.val('').css('height', '');
        showConfirm(null);
        addMessage('user', text);
        post(cfg.urls.send, { message: text });
    }

    function loadHistory() {
        if (loaded) {
            return;
        }
        loaded = true;
        $.getJSON(cfg.urls.history).done(function (res) {
            $messages.empty();
            if (!res.display || !res.display.length) {
                addMessage('assistant', cfg.lang.welcome);
            } else {
                res.display.forEach(function (m) { addMessage(m.role, m.text); });
            }
            showConfirm(res.pending);
        }).fail(function () {
            addMessage('assistant', cfg.lang.welcome);
        });
    }

    function open(state) {
        if (state) {
            $panel.removeAttr('hidden');
            loadHistory();
            $text.trigger('focus');
        } else {
            $panel.attr('hidden', true);
        }
        store('ai_mcp_chat_open', state ? '1' : '0');
    }

    $('#ai-mcp-fab').on('click', function () { open($panel.is('[hidden]')); });
    $('#ai-mcp-close').on('click', function () { open(false); });
    $('#ai-mcp-form').on('submit', function (e) { e.preventDefault(); send(); });
    $text.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    }).on('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$panel.is('[hidden]')) {
            open(false);
        }
    });
    $('#ai-mcp-reset').on('click', function () {
        if (busy || !window.confirm(cfg.lang.resetConfirm)) {
            return;
        }
        $.post(cfg.urls.reset, csrfData()).always(function () {
            $messages.empty();
            showConfirm(null);
            addMessage('assistant', cfg.lang.welcome);
        });
    });

    if (store('ai_mcp_chat_open') === '1') {
        open(true);
    }
})(window.jQuery);
