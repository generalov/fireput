FBL.ns(function() {with (FBL) {

const Cc = Components.classes;
const Ci = Components.interfaces;

var T = function() {
    if (FBTrace.DBG_FIREPUT) {
        arguments[0] = "Firebug.FireputModule: " + arguments[0];
        FBTrace.sysout.apply(FBTrace.sysout, arguments);
    }
}

// ************************************************************************************************
// Module implementation
Firebug.FireputModule = extend(Firebug.ActivableModule,
{
    panelName: 'fireput',
    enabled: null,
    
    initialize: function(owner)
    {
        Firebug.ActivableModule.initialize.apply(this, arguments);
        T("begin intialization");

        if (Firebug.CSSModule) {
            T("listen for Firebug.CSSModule events");
            // Maintain support for older versions of firebug that do not
            // have the CSS change event implementation
            Firebug.CSSModule.addListener(this);
        }
        T("initialization done");

    },

    shutdown: function()
    {
        Firebug.Module.shutdown.apply(this, arguments);

        if (Firebug.CSSModule) {
            Firebug.CSSModule.removeListener(this);
        }
    },

    //////////////////////////////////////////////
    // CSSModule Listener
    onCSSFreeEdit: function(styleSheet, cssText)
    {
            T('FireputModule.onCSSFreeEdit', styleSheet.href);

            this.sendMessage({
                type: 'CSS',
                subType: 'freeEdit',
                href: styleSheet.href,
                cssText: cssText
            });
    },

    onCSSInsertRule: function(styleSheet, cssText, ruleIndex) {
            T('onCSSInsertRule');
            this.sendMessage({
                type: 'CSS',
                subType: 'insertRule',
                href: styleSheet.href,
                ruleIndex: ruleIndex,
                cssText: cssText
            });
    },

    onCSSDeleteRule: function(styleSheet, ruleIndex) {
            T('onCSSDeleteRule');
            this.sendMessage({
                type: 'CSS',
                subType: 'deleteRule',
                href: styleSheet.href,
                ruleIndex: ruleIndex
            });
    },
    
    onCSSSetProperty: function(styleDeclaration, propName, propValue, propPriority, prevValue, prevPriority, parent, baseText)
    {
            T('onCSSSetProperty');
            this.sendMessage({
                type: 'CSS',
                subType: 'setProperty',
                href: parent.parentStyleSheet.href,
                propName: propName,
                propValue: propValue,
                propPriority: propPriority,
                prevValue: prevValue,
                prevPriority: prevPriority,
                ruleSelector: parent.selectorText
            });
    },
    
    onCSSRemoveProperty: function(style, propName, prevValue, prevPriority, parent, baseText) {
            FBTrace.sysout('onCSSRemoveProperty');
            this.sendMessage({
                type: 'CSS',
                subType: 'removeProperty',
                href: parent.parentStyleSheet.href,
                propName: propName,
                prevValue: prevValue,
                prevPriority: prevPriority,
                ruleSelector: parent.selectorText
            });
    },
    
    sendMessage: function(message) {
            if (!this.enabled) return;
            var fireputUri = top.gBrowser.currentURI.prePath + "/fireput/fireput.php";
            var content = this.encodeToJSON(message);
            var contentType = 'application/json';

            if (FBTrace.DBG_FIREPUT)
                FBTrace.sysout('FireputModule.sendMessage to %s', fireputUri);

            // POST TO SERVER
            xmlhttp = new XMLHttpRequest();
            xmlhttp.open("POST", fireputUri, true);
            xmlhttp.setRequestHeader('Content-Type', contentType);

			// SETUP TRANSFER
			xmlhttp.onreadystatechange = function(e) {
				var xmlhttp = e.currentTarget;
                
				if (xmlhttp.readyState == 4) {
                    FBTrace.sysout('response: ' + xmlhttp.status);
                    FBTrace.sysout(xmlhttp.responseText);
                    if (xmlhttp.status == 200) {

                    }
				}
			}

            // START TRANSFER
            xmlhttp.send(content);
            return true;
    },

    encodeToJSON: function(struct) {
            // Convert data to JSON.
            var nativeJSON = Cc["@mozilla.org/dom/json;1"].createInstance(Ci.nsIJSON);
            var jsonString = nativeJSON.encode(struct);
            return jsonString;
    },

    onEnabled: function(context) {
        this.enabled = true;
        var panel = context.getPanel(this.panelName);
        var parentNode = panel.panelNode;
        parentNode.innerHTML = '<em>enabled</em>';
        T("enabled");
    },
    
    onDisabled: function(context) {
        this.enabled = false;
        T("disabled");
    }
});


// ************************************************************************************************
// Registration

Firebug.registerActivableModule(Firebug.FireputModule);

// ************************************************************************************************


function FireputPanel() {}
FireputPanel.prototype = extend(Firebug.ActivablePanel,
{
    name: "fireput",
    
    title: "Fireput",

    getOptionsMenuItems: function(context)    {
        return [];
    }
});


// ************************************************************************************************
// Registration
Firebug.registerPanel(FireputPanel);

// ************************************************************************************************

}});
