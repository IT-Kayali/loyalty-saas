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

  document.addEventListener("DOMContentLoaded", function () {
    initQr();
    initDesigner();
  });
})();
