fireput.onFirefoxLoad = function(event) {
  document.getElementById("contentAreaContextMenu")
          .addEventListener("popupshowing", function (e){ fireput.showFirefoxContextMenu(e); }, false);
};

fireput.showFirefoxContextMenu = function(event) {
  // show or hide the menuitem based on what the context menu is on
  document.getElementById("context-fireput").hidden = gContextMenu.onImage;
};

window.addEventListener("load", fireput.onFirefoxLoad, false);
