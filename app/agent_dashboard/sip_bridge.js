// SkyKin SIP.js Bridge — complete self-contained bundle entry
// Built with: npx esbuild sip_bridge.js --bundle --format=iife --outfile=sipjs.bundle.js

import {
    UserAgent, Registerer, RegistererState,
    SessionState, UserAgentState, Inviter
} from 'sip.js';

(function() {
    'use strict';

    let ua = null, reg = null, session = null;
    let onCallCb = null, onEndCb = null;

    const bridge = window.sipBridge = window.sipBridge || {};

    bridge.init = function(ext, pass, server, port, dom) {
        // Stop any existing UA cleanly
        if (ua) {
            try { if (reg) reg.unregister(); ua.stop(); } catch(e) {}
            ua = null; reg = null;
        }

        // Build WebSocket URI properly
        let wsUri = server;
        if (!wsUri.startsWith('wss://') && !wsUri.startsWith('ws://')) {
            const isHttps = typeof location !== 'undefined' && location.protocol === 'https:';
            wsUri = (isHttps ? 'wss://' : 'ws://') + wsUri;
        }
        
        // If the WebSocket URI does not specify a port or sub-path, add one.
        // HTTPS gets the nginx /wss/ proxy path so the socket shares the page cert.
        const hostPart = wsUri.replace(/^wss?:\/\//i, '');
        if (!hostPart.includes('/') && !hostPart.includes(':')) {
            const isHttps = typeof location !== 'undefined' && location.protocol === 'https:';
            wsUri = isHttps ? wsUri + '/wss/' : wsUri + ':' + (port || '5066');
        }

        try {
            ua = new UserAgent({
                uri:              UserAgent.makeURI('sip:' + ext + '@' + dom),
                transportOptions: { server: wsUri },
                authorizationUsername: ext,
                authorizationPassword: pass,
                logLevel: 'error',
            });

            ua.stateChange.addListener(state => {
                if (state === UserAgentState.Stopped) {
                    window.setSipStatus && window.setSipStatus('unregistered', 'Disconnected');
                }
            });

            // Handle incoming calls
            ua.delegate = {
                onInvite: function(inv) {
                    session = inv;
                    const callerNum = inv.remoteIdentity.uri.user || 'Unknown';
                    window.handleIncoming && window.handleIncoming(callerNum);
                    window.currentSession = inv;

                    inv.stateChange.addListener(state => {
                        if (state === SessionState.Terminated) {
                            window.endCall && window.endCall();
                            session = null;
                        }
                        if (state === SessionState.Established) {
                            window.setSipStatus && window.setSipStatus('incall', 'In Call');
                            window.startCallTimer && window.startCallTimer();
                            // Attach remote audio
                            const peerConn = inv.sessionDescriptionHandler?.peerConnection;
                            if (peerConn) _attachAudio(peerConn);
                        }
                    });
                }
            };

            ua.start().then(() => {
                reg = new Registerer(ua);
                reg.stateChange.addListener(state => {
                    if (state === RegistererState.Registered) {
                        window.setSipStatus && window.setSipStatus('registered', 'Registered (' + ext + ')');
                    } else if (state === RegistererState.Unregistered) {
                        window.setSipStatus && window.setSipStatus('unregistered', 'Not Registered');
                    } else if (state === RegistererState.Terminated) {
                        window.setSipStatus && window.setSipStatus('failed', 'Registration Failed');
                    }
                });
                reg.register();
            }).catch(err => {
                window.setSipStatus && window.setSipStatus('failed', 'WebSocket closed ' + wsUri + ' (code: 1006)');
            });

        } catch(err) {
            window.setSipStatus && window.setSipStatus('failed', 'SIP Error: ' + err.message);
        }
    };

    bridge.call = function(target, dom) {
        if (!ua) return;
        const targetUri = UserAgent.makeURI('sip:' + target + '@' + dom);
        if (!targetUri) return;
        const inviter = new Inviter(ua, targetUri);
        session = inviter;
        window.currentSession = inviter;

        inviter.stateChange.addListener(state => {
            if (state === SessionState.Establishing) {
                window.setSipStatus && window.setSipStatus('calling', 'Calling ' + target);
            }
            if (state === SessionState.Established) {
                window.setSipStatus && window.setSipStatus('incall', 'In Call');
                window.startCallTimer && window.startCallTimer();
                const peerConn = inviter.sessionDescriptionHandler?.peerConnection;
                if (peerConn) _attachAudio(peerConn);
            }
            if (state === SessionState.Terminated) {
                window.endCall && window.endCall();
                session = null;
            }
        });

        inviter.invite().catch(err => {
            window.setSipStatus && window.setSipStatus('failed', 'Call failed: ' + err.message);
        });
    };

    bridge.answer = function() {
        if (!session || !session.accept) return;
        const options = {
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: false }
            }
        };
        session.accept(options).then(() => {
            window.setSipStatus && window.setSipStatus('incall', 'In Call');
            window.startCallTimer && window.startCallTimer();
            const peerConn = session.sessionDescriptionHandler?.peerConnection;
            if (peerConn) _attachAudio(peerConn);
        });
    };

    bridge.hangup = function() {
        if (!session) return;
        try {
            if (session.state === SessionState.Established) {
                session.bye();
            } else {
                session.reject && session.reject();
                session.cancel && session.cancel();
            }
        } catch(e) {}
        session = null;
        window.endCall && window.endCall();
    };

    bridge.hold = function() {
        if (session && session.hold) session.hold();
    };

    bridge.unhold = function() {
        if (session && session.unhold) session.unhold();
    };

    bridge.mute = function() {
        const peerConn = session?.sessionDescriptionHandler?.peerConnection;
        if (peerConn) peerConn.getSenders().forEach(s => { if (s.track) s.track.enabled = false; });
    };

    bridge.unmute = function() {
        const peerConn = session?.sessionDescriptionHandler?.peerConnection;
        if (peerConn) peerConn.getSenders().forEach(s => { if (s.track) s.track.enabled = true; });
    };

    bridge.sendDtmf = function(tone) {
        if (session && session.sessionDescriptionHandler) {
            session.sessionDescriptionHandler.sendDtmf(tone);
        }
    };

    function _attachAudio(peerConn) {
        let audioEl = document.getElementById('remoteAudio');
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.id = 'remoteAudio';
            audioEl.autoplay = true;
            document.body.appendChild(audioEl);
        }
        peerConn.ontrack = function(e) {
            if (e.streams && e.streams[0]) audioEl.srcObject = e.streams[0];
        };
        // Also attach existing tracks
        const remoteStream = new MediaStream();
        peerConn.getReceivers().forEach(r => { if (r.track) remoteStream.addTrack(r.track); });
        if (remoteStream.getTracks().length) audioEl.srcObject = remoteStream;
    }

})();
