(function($) {
  function getThumbDataFromRow($row) {
    var $cell = $row.find('td.column-gec_cover_thumb .gec-event-thumb');
    return {
      id: parseInt($cell.data('gec-thumb-id') || 0, 10) || 0,
      url: String($cell.data('gec-thumb-url') || '')
    };
  }

  function setPreview($inline, id, url) {
    var $imgBox = $inline.find('.gec-qe-thumb-img');
    var $input = $inline.find('input[name="gec_thumbnail_id"]');

    $input.val(id ? String(id) : '');

    if (!$imgBox.length) return;
    if (!id || !url) {
      $imgBox.html('');
      $imgBox.css({
        background: '#f8fafc',
        borderStyle: 'dashed'
      });
      return;
    }

    var html = '<img alt="" src="' + url + '" style="width:60px;height:60px;object-fit:cover;display:block;" />';
    $imgBox.html(html);
    $imgBox.css({
      background: '#fff',
      borderStyle: 'solid'
    });
  }

  // Extend Quick Edit populate.
  var $wpInlineEdit = inlineEditPost.edit;
  inlineEditPost.edit = function(id) {
    $wpInlineEdit.apply(this, arguments);

    var postId = 0;
    if (typeof id === 'object') {
      postId = parseInt(this.getId(id), 10) || 0;
    } else {
      postId = parseInt(id, 10) || 0;
    }
    if (!postId) return;

    var $row = $('#post-' + postId);
    var $inline = $('#edit-' + postId);
    if (!$row.length || !$inline.length) return;

    var data = getThumbDataFromRow($row);
    setPreview($inline, data.id, data.url);
  };

  // Media picker.
  $(document).on('click', '.gec-qe-thumb-pick', function(e) {
    e.preventDefault();
    var $inline = $(this).closest('.inline-edit-row');
    if (!$inline.length) return;

    var frame = wp.media({
      title: 'Scegli immagine copertina',
      button: { text: 'Usa questa immagine' },
      multiple: false
    });

    frame.on('select', function() {
      var attachment = frame.state().get('selection').first().toJSON();
      var url = attachment && attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : (attachment.url || '');
      setPreview($inline, parseInt(attachment.id, 10) || 0, url);
    });

    frame.open();
  });

  $(document).on('click', '.gec-qe-thumb-remove', function(e) {
    e.preventDefault();
    var $inline = $(this).closest('.inline-edit-row');
    setPreview($inline, 0, '');
  });
})(jQuery);

