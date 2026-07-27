// SkyKin SIP.js globals exporter
// Build: npx esbuild sip_entry.js --bundle --format=iife --platform=browser --outfile=sipjs.bundle.js

import {
    UserAgent,
    Registerer,
    RegistererState,
    SessionState,
    UserAgentState,
    Inviter,
    Invitation
} from 'sip.js';

window.UserAgent       = UserAgent;
window.Registerer      = Registerer;
window.RegistererState = RegistererState;
window.SessionState    = SessionState;
window.UserAgentState  = UserAgentState;
window.Inviter         = Inviter;
window.Invitation      = Invitation;
