/* Loyalty SaaS - Frontend
   - QR-Codes mit Firmenlogo in der Mitte (hohe Fehlerkorrektur, Logo auf weissem Badge)
   - PNG-Download und Drucken aus dem QR-Studio
   - Karten-Designer: Live-Vorschau
*/
(function () {
  "use strict";

  function renderQrBox(box) {
    var text = box.getAttribute("data-qr");
    if (!text || !window.QRCode) return;
    var size = parseInt(box.getAttribute("data-qr-size") || "200", 10);
    var logoUrl = box.getAttribute("data-qr-logo") || "";
    var brand = box.getAttribute("data-qr-brand") || "";

    var tmp = document.createElement("div");
    new QRCode(tmp, {
      text: text,
      width: size * 2,
      height: size * 2,
      correctLevel: QRCode.CorrectLevel.H
    });

    function compose(qrImg) {
      var scale = 2;
      var S = size * scale;
      var canvas = document.createElement("canvas");
      canvas.width = S;
      canvas.height = S;
      canvas.style.width = size + "px";
      canvas.style.height = size + "px";
      canvas.className = "qr-canvas";
      var ctx = canvas.getContext("2d");
      ctx.fillStyle = "#ffffff";
      ctx.fillRect(0, 0, S, S);
      ctx.drawImage(qrImg, 0, 0, S, S);

      function finish() {
        box.innerHTML = "";
        box.appendChild(canvas);
        box.classList.add("qr-ready");
      }

      if (!logoUrl) { finish(); return; }
      var logo = new Image();
      logo.crossOrigin = "anonymous";
      logo.onload = function () {
        var badge = S * 0.24;
        var pad = S * 0.018;
        var x = (S - badge) / 2;
        var y = (S - badge) / 2;
        var r = S * 0.035;
        ctx.fillStyle = "#ffffff";
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x - pad, y - pad, badge + pad * 2, badge + pad * 2, r);
        else ctx.rect(x - pad, y - pad, badge + pad * 2, badge + pad * 2);
        ctx.fill();
        if (brand) {
          ctx.strokeStyle = brand;
          ctx.lineWidth = Math.max(2, S * 0.006);
          ctx.stroke();
        }
        /* Logo unverzerrt in den Badge-Rahmen */
        var lw = logo.width, lh = logo.height;
        var s = Math.min(badge / lw, badge / lh);
        var dw = lw * s, dh = lh * s;
        ctx.drawImage(logo, (S - dw) / 2, (S - dh) / 2, dw, dh);
        finish();
      };
      logo.onerror = finish;
      logo.src = logoUrl;
    }

    /* qrcode.min.js rendert img (oder canvas) asynchron */
    var tries = 0;
    (function wait() {
      var img = tmp.querySelector("img");
      var cnv = tmp.querySelector("canvas");
      if (img && img.src) { compose(img); return; }
      if (cnv) {
        var i = new Image();
        i.onload = function () { compose(i); };
        i.src = cnv.toDataURL("image/png");
        return;
      }
      if (++tries < 40) setTimeout(wait, 50);
    })();
  }

  function initQr() {
    document.querySelectorAll(".qr-box").forEach(renderQrBox);

    document.querySelectorAll(".qr-download").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var item = btn.closest(".qr-item");
        var canvas = item && item.querySelector("canvas");
        if (!canvas) return;
        var a = document.createElement("a");
        a.download = (btn.getAttribute("data-name") || "qr-code") + ".png";
        a.href = canvas.toDataURL("image/png");
        a.click();
      });
    });

    document.querySelectorAll(".qr-print").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var item = btn.closest(".qr-item");
        var canvas = item && item.querySelector("canvas");
        var title = item && item.querySelector("h3") ? item.querySelector("h3").textContent : "";
        if (!canvas) return;
        var w = window.open("", "_blank", "width=420,height=560");
        if (!w) return;
        w.document.write("<!DOCTYPE html><html><head><title>" + title + "</title></head><body style='text-align:center;font-family:sans-serif'>"
          + "<h2>" + title + "</h2><img src='" + canvas.toDataURL("image/png") + "' style='width:320px'>"
          + "<scr" + "ipt>window.onload=function(){window.print()}<\/scr" + "ipt></body></html>");
        w.document.close();
      });
    });
  }

  /* Karten-Designer: Vorschau befuellen (Statische Werte reichen - Live-Update bei Speichern) */
  function initDesigner() {
    var prev = document.getElementById("cardPreview");
    if (!prev) return;
    var d = prev.dataset;
    prev.style.setProperty("--cp-bg", d.bg);
    prev.style.setProperty("--cp-text", d.text);
    prev.style.setProperty("--cp-accent", d.accent);
    prev.querySelector(".cp-header").textContent = d.header || "";
    prev.querySelector(".cp-footer").textContent = d.footer || "";
    var logo = prev.querySelector(".cp-logo");
    if (d.logo) { logo.src = d.logo; logo.style.display = "block"; }
    var stamps = prev.querySelector(".cp-stamps");
    function drawStamps(url) {
      stamps.textContent = "";
      for (var i = 0; i < 6; i++) {
        var dot = document.createElement("span");
        dot.className = "cp-dot" + (i < 4 ? " filled" : "");
        if (url) {
          dot.classList.add("custom-stamp");
          var img = document.createElement("img"); img.src = url; img.alt = "Stempel";
          dot.appendChild(img);
        } else if (d.icon) { dot.classList.add("emoji-stamp");dot.textContent=d.icon; }
        stamps.appendChild(dot);
      }
    }
    var stampUrl=d.stamp;
    drawStamps(stampUrl);
    function drawBackground() {
      var opacity=(parseInt(d.opacity || "75",10)/100);
      var color=form ? form.elements.bg_color.value : d.bg;
      var rgb=[parseInt(color.slice(1,3),16),parseInt(color.slice(3,5),16),parseInt(color.slice(5,7),16)];
      prev.style.backgroundImage=d.background ? "linear-gradient(rgba("+rgb.join(",")+","+opacity+"),rgba("+rgb.join(",")+","+opacity+")),url("+JSON.stringify(d.background)+")" : "none";
      prev.style.backgroundSize="cover";prev.style.backgroundPosition="center";
    }
    var form = document.querySelector(".designer-grid form");
    if (form) {
      if(form.elements.stamp_icon)form.elements.stamp_icon.addEventListener("change",function(){d.icon=this.value;drawStamps(stampUrl);});
      if(form.elements.overlay_opacity)form.elements.overlay_opacity.addEventListener("input",function(){d.opacity=this.value;drawBackground();});
      drawBackground();
      ["bg_color", "text_color", "accent_color"].forEach(function (name, i) {
        form.elements[name].addEventListener("input", function () {
          prev.style.setProperty(["--cp-bg", "--cp-text", "--cp-accent"][i], this.value);drawBackground();
        });
      });
      ["header", "footer"].forEach(function(name) {
        form.elements[name].addEventListener("input", function() {
          prev.querySelector(".cp-" + name).textContent = this.value;
        });
      });
      ["logo", "stamp_image", "background_image"].forEach(function(name) {
        form.elements[name].addEventListener("change", function() {
          var file = this.files[0];
          if (!file || file.size > 2097152 || !/^image\/(png|jpeg|webp)$/.test(file.type)) return;
          var reader = new FileReader();
          reader.onload = function() {
            if (name === "stamp_image") {stampUrl=reader.result;drawStamps(stampUrl);}
            else if(name === "background_image") {d.background=reader.result;drawBackground();}
            else { logo.src = reader.result; logo.style.display = "block"; }
          };
          reader.readAsDataURL(file);
        });
      });
    }
    var qr = prev.querySelector(".cp-qr");
    qr.setAttribute("data-qr", "https://example.com/c/DEMO1234AB");
    qr.setAttribute("data-qr-size", "72");
    qr.classList.add("qr-box");
    if (d.logo) qr.setAttribute("data-qr-logo", d.logo);
    renderQrBox(qr);
  }

  /* Gestaltete Webkarte: Vorder-/Rueckseite umschalten */
  function initFaces() {
    document.querySelectorAll(".cl-wrap").forEach(function (w) {
      w.setAttribute("data-js", "1");
      w.querySelectorAll(".cl-tab").forEach(function (b) {
        b.addEventListener("click", function () {
          w.setAttribute("data-side", b.getAttribute("data-side"));
          w.querySelectorAll(".cl-tab").forEach(function (o) { o.classList.toggle("active", o === b); });
        });
      });
    });
  }

  /* Designer: Live-Vorschau und Ziehen der Elemente */
  function initLayoutPreview() {
    var wrap = document.getElementById("layoutPreview");
    var form = document.querySelector(".designer-grid form");
    if (!wrap || !form) return;
    function f(n) { return form.elements[n]; }
    function rgb(hex) { return [parseInt(hex.slice(1, 3), 16), parseInt(hex.slice(3, 5), 16), parseInt(hex.slice(5, 7), 16)]; }
    function bg(side) {
      var face = wrap.querySelector('.cl-face[data-side="' + side + '"]');
      var same = f("lay_" + side + "_overlay_same").checked;
      var col = same ? f("bg_color").value : f("lay_" + side + "_overlay_color").value;
      var op = (side === "front" ? f("overlay_opacity").value : f("lay_back_overlay_opacity").value) / 100;
      var img = face.getAttribute("data-image");
      face.style.setProperty("--card-bg", f("bg_color").value);
      face.style.setProperty("--card-text", f("text_color").value);
      face.style.setProperty("--brand", f("accent_color").value);
      if (img) {
        var c = "rgba(" + rgb(col).join(",") + "," + op + ")";
        face.style.backgroundImage = "linear-gradient(" + c + "," + c + "),url(" + JSON.stringify(img) + ")";
        face.style.backgroundSize = "cover"; face.style.backgroundPosition = "center";
      } else { face.style.backgroundImage = "none"; }
    }
    function el(side, name) { return wrap.querySelector('.cl-el[data-side="' + side + '"][data-el="' + name + '"]'); }
    function upd(side, name) {
      var n = el(side, name), p = "lay_" + side + "_" + name + "_";
      if (!n) return;
      n.style.display = f(p + "show").checked ? "" : "none";
      n.style.left = f(p + "x").value + "%"; n.style.top = f(p + "y").value + "%";
      if (name === "qr") n.style.width = f(p + "size").value + "cqw";
      if (name === "stamps") n.style.setProperty("--sz", f(p + "size").value + "cqw");
      if (name !== "qr") n.style.maxWidth = "calc(100% - " + f(p + "x").value + "% - 3%)";
      if (name === "title" || name === "text") {
        var t = f(p + "text").value;
        if (name === "title" && !t) t = side === "front" ? n.getAttribute("data-default") : n.getAttribute("data-default");
        n.textContent = t;
      }
    }
    ["front", "back"].forEach(function (s) {
      ["title", "text", "reward", "stamps", "qr"].forEach(function (e) {
        var n = el(s, e);
        if (n && (e === "title")) n.setAttribute("data-default", n.textContent);
        upd(s, e);
      });
      bg(s);
    });
    form.addEventListener("input", function (ev) {
      var m = /^lay_(front|back)_(title|text|reward|stamps|qr)_/.exec(ev.target.name || "");
      if (m) upd(m[1], m[2]);
      bg("front"); bg("back");
    });
    form.addEventListener("change", function (ev) {
      var n = ev.target.name;
      if (n !== "background_image" && n !== "lay_back_image") return;
      var file = ev.target.files && ev.target.files[0];
      if (!file || file.size > 2097152 || !/^image\/(png|jpeg|webp)$/.test(file.type)) return;
      var r = new FileReader();
      r.onload = function () {
        wrap.querySelector('.cl-face[data-side="' + (n === "lay_back_image" ? "back" : "front") + '"]').setAttribute("data-image", r.result);
        bg("front"); bg("back");
      };
      r.readAsDataURL(file);
    });
    wrap.querySelectorAll(".cl-el").forEach(function (n) {
      n.addEventListener("pointerdown", function (ev) {
        var face = n.parentNode, rect = face.getBoundingClientRect();
        var side = n.getAttribute("data-side"), name = n.getAttribute("data-el"), p = "lay_" + side + "_" + name + "_";
        var sx = ev.clientX, sy = ev.clientY, x0 = parseFloat(f(p + "x").value), y0 = parseFloat(f(p + "y").value);
        n.classList.add("drag"); n.setPointerCapture(ev.pointerId); ev.preventDefault();
        function mv(e) {
          f(p + "x").value = Math.max(0, Math.min(95, Math.round(x0 + (e.clientX - sx) / rect.width * 100)));
          f(p + "y").value = Math.max(0, Math.min(95, Math.round(y0 + (e.clientY - sy) / rect.height * 100)));
          upd(side, name);
        }
        function up() { n.classList.remove("drag"); n.removeEventListener("pointermove", mv); n.removeEventListener("pointerup", up); n.removeEventListener("pointercancel", up); }
        n.addEventListener("pointermove", mv); n.addEventListener("pointerup", up); n.addEventListener("pointercancel", up);
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initQr();
    initDesigner();
    initFaces();
    initLayoutPreview();
  });
})();
