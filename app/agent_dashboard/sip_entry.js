// SkyKin SIP.js globals exporter
// Build: npx esbuild sip_entry.js --bundle --format=iife --platform=browser --outfile=sipjs.bundle.js

import * as SIPjs from 'sip.js';

window.SIPjs = SIPjs;

window.UserAgent       = SIPjs.UserAgent;
window.Registerer      = SIPjs.Registerer;
window.RegistererState = SIPjs.RegistererState;
window.SessionState    = SIPjs.SessionState;
window.UserAgentState  = SIPjs.UserAgentState;
window.Inviter         = SIPjs.Inviter;
window.Invitation      = SIPjs.Invitation;
