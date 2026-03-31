(function () {
  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function setStatus(text) {
    var el = qs("#gec-booking-modal-status");
    if (!el) return;
    el.textContent = text || "";
  }

  function openThickbox() {
    if (typeof window.tb_show !== "function") return;
    window.tb_show("Prenotazione", "#TB_inline?inlineId=gec-booking-modal&width=900&height=600");
  }

  function closeThickbox() {
    if (typeof window.tb_remove !== "function") return;
    window.tb_remove();
  }

  function renderBooking(data) {
    qs("#gec-booking-id").textContent = data.booking.id;
    qs("#gec-booking-member").textContent = data.booking.member_name + " (" + data.booking.member_code + ")";
    qs("#gec-booking-email").textContent = data.booking.member_email;
    qs("#gec-booking-event").textContent = data.booking.event_title;
    qs("#gec-booking-created").textContent = data.booking.created_at_formatted;

    var notes = qs("#gec-booking-notes");
    notes.value = data.booking.notes || "";

    var status = qs("#gec-booking-status");
    status.value = data.booking.status || "confirmed";

    var guestsWrap = qs("#gec-booking-guests");
    guestsWrap.innerHTML = "";

    if (!data.guests || !data.guests.length) {
      guestsWrap.innerHTML = "<p>Nessun accompagnatore.</p>";
    } else {
      data.guests.forEach(function (g) {
        var row = document.createElement("div");
        row.className = "gec-guest-row";
        row.innerHTML =
          '<label style="display:block;margin:6px 0;">' +
          "<strong>" + g.guest_type + "</strong>" +
          ' <span style="opacity:.7">(€ ' + g.unit_price_formatted + ")</span>" +
          "</label>" +
          '<div style="display:flex;gap:8px;align-items:center;max-width:780px;">' +
          '<input type="text" class="regular-text gec-guest-name" data-guest-id="' +
          g.id +
          '" value="' +
          (g.guest_name || "") +
          '" placeholder="Nome Cognome" />';
        row.innerHTML +=
          '<button type="button" class="button" data-gec-remove-guest="' + g.id + '">Rimuovi</button>' +
          "</div>";
        guestsWrap.appendChild(row);
      });
    }

    qs("#gec-booking-total").textContent = data.booking.total_amount_formatted;

    // Add guest form.
    var addWrap = qs("#gec-booking-add-guest");
    addWrap.innerHTML = "";

    if (data.guest_types && data.guest_types.length) {
      var select = document.createElement("select");
      select.id = "gec-add-guest-type";
      data.guest_types.forEach(function (t) {
        var opt = document.createElement("option");
        opt.value = t.label;
        opt.textContent = t.label + " (€ " + t.price_formatted + ")";
        select.appendChild(opt);
      });

      var input = document.createElement("input");
      input.type = "text";
      input.id = "gec-add-guest-name";
      input.className = "regular-text";
      input.placeholder = "Nome Cognome";

      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "button button-primary";
      btn.id = "gec-add-guest-btn";
      btn.textContent = "Aggiungi accompagnatore";

      var row = document.createElement("div");
      row.style.display = "flex";
      row.style.gap = "8px";
      row.style.alignItems = "center";
      row.style.maxWidth = "780px";
      row.appendChild(select);
      row.appendChild(input);
      row.appendChild(btn);
      addWrap.appendChild(row);
    } else {
      addWrap.innerHTML = "<p>Nessuna tipologia accompagnatore configurata per questo evento.</p>";
    }
  }

  function fetchBooking(bookingId) {
    setStatus("Caricamento...");
    return window
      .fetch(window.gecAttendees.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body:
          "action=gec_get_booking_details&booking_id=" +
          encodeURIComponent(bookingId) +
          "&_ajax_nonce=" +
          encodeURIComponent(window.gecAttendees.nonce),
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "Errore caricamento");
        }
        renderBooking(json.data);
        setStatus("");
      })
      .catch(function (e) {
        setStatus(e.message || "Errore");
      });
  }

  function saveBooking() {
    var bookingId = qs("#gec-booking-id").textContent;
    var notes = qs("#gec-booking-notes").value || "";
    var status = qs("#gec-booking-status").value || "confirmed";

    var guests = qsa(".gec-guest-name").map(function (input) {
      return {
        id: parseInt(input.getAttribute("data-guest-id"), 10),
        guest_name: input.value || "",
      };
    });

    setStatus("Salvataggio...");

    return window
      .fetch(window.gecAttendees.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body:
          "action=gec_update_booking&booking_id=" +
          encodeURIComponent(bookingId) +
          "&notes=" +
          encodeURIComponent(notes) +
          "&status=" +
          encodeURIComponent(status) +
          "&guests=" +
          encodeURIComponent(JSON.stringify(guests)) +
          "&_ajax_nonce=" +
          encodeURIComponent(window.gecAttendees.nonce),
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "Errore salvataggio");
        }
        setStatus("Salvato. Aggiorno lista...");
        window.location.reload();
      })
      .catch(function (e) {
        setStatus(e.message || "Errore");
      });
  }

  function addGuest() {
    var bookingId = qs("#gec-booking-id").textContent;
    var type = qs("#gec-add-guest-type").value;
    var name = qs("#gec-add-guest-name").value || "";

    if (!name.trim()) {
      setStatus("Inserisci Nome Cognome.");
      return;
    }

    setStatus("Aggiunta accompagnatore...");
    window
      .fetch(window.gecAttendees.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body:
          "action=gec_add_guest&booking_id=" +
          encodeURIComponent(bookingId) +
          "&guest_type=" +
          encodeURIComponent(type) +
          "&guest_name=" +
          encodeURIComponent(name) +
          "&_ajax_nonce=" +
          encodeURIComponent(window.gecAttendees.nonce),
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "Errore aggiunta");
        }
        return fetchBooking(bookingId);
      })
      .then(function () {
        var nameInput = qs("#gec-add-guest-name");
        if (nameInput) nameInput.value = "";
        setStatus("");
      })
      .catch(function (e) {
        setStatus(e.message || "Errore");
      });
  }

  function removeGuest(guestId) {
    var bookingId = qs("#gec-booking-id").textContent;
    if (!window.confirm("Rimuovere questo accompagnatore?")) return;

    setStatus("Rimozione accompagnatore...");
    window
      .fetch(window.gecAttendees.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body:
          "action=gec_delete_guest&booking_id=" +
          encodeURIComponent(bookingId) +
          "&guest_id=" +
          encodeURIComponent(guestId) +
          "&_ajax_nonce=" +
          encodeURIComponent(window.gecAttendees.nonce),
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "Errore rimozione");
        }
        return fetchBooking(bookingId);
      })
      .then(function () {
        setStatus("");
      })
      .catch(function (e) {
        setStatus(e.message || "Errore");
      });
  }

  function deleteBooking(bookingId) {
    if (!window.confirm("Eliminare questa prenotazione?")) return;

    setStatus("Eliminazione...");
    window
      .fetch(window.gecAttendees.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body:
          "action=gec_delete_booking&booking_id=" +
          encodeURIComponent(bookingId) +
          "&_ajax_nonce=" +
          encodeURIComponent(window.gecAttendees.nonce),
        credentials: "same-origin",
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || "Errore eliminazione");
        }
        window.location.reload();
      })
      .catch(function (e) {
        setStatus(e.message || "Errore");
      });
  }

  document.addEventListener("click", function (e) {
    var viewBtn = e.target.closest("[data-gec-view-booking]");
    if (viewBtn) {
      e.preventDefault();
      var bookingId = viewBtn.getAttribute("data-gec-view-booking");
      openThickbox();
      fetchBooking(bookingId);
      return;
    }

    var delBtn = e.target.closest("[data-gec-delete-booking]");
    if (delBtn) {
      e.preventDefault();
      var bookingIdDel = delBtn.getAttribute("data-gec-delete-booking");
      openThickbox();
      // ensure modal exists to show status
      setTimeout(function () {
        deleteBooking(bookingIdDel);
      }, 0);
      return;
    }

    if (e.target && e.target.id === "gec-booking-save") {
      e.preventDefault();
      saveBooking();
      return;
    }

    if (e.target && e.target.id === "gec-add-guest-btn") {
      e.preventDefault();
      addGuest();
      return;
    }

    var removeGuestBtn = e.target.closest("[data-gec-remove-guest]");
    if (removeGuestBtn) {
      e.preventDefault();
      removeGuest(removeGuestBtn.getAttribute("data-gec-remove-guest"));
      return;
    }

    if (e.target && e.target.id === "gec-booking-close") {
      e.preventDefault();
      closeThickbox();
      return;
    }
  });
})();

