/**
 * @file
 * Keeps the "Edit this view template in Canvas" link in the Views UI Format
 * settings in step with the selected template.
 */
((Drupal, once, drupalSettings) => {
  Drupal.behaviors.canvasViewTemplateStyleOptions = {
    attach(context) {
      const urls = drupalSettings.canvasViewTemplate?.editUrls ?? {};
      once('cvt-edit-link', '[data-cvt-template-select]', context).forEach((select) => {
        const wrapper = select.closest('form')?.querySelector('[data-cvt-edit-wrapper]');
        const link = wrapper?.querySelector('a');
        if (!wrapper || !link) {
          return;
        }
        const update = () => {
          const url = urls[select.value];
          wrapper.hidden = !url;
          if (url) {
            link.href = url;
          }
        };
        select.addEventListener('change', update);
        update();
      });
    },
  };
})(Drupal, once, drupalSettings);
