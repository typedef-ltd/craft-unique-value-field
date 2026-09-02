;(() =>
{
    document.addEventListener('click', event =>
    {
        const button = event.target.closest('.unique_value_field_copy_button')
        if (!button)
        {
            return
        }

        event.preventDefault()

        const input = button.closest('.unique_value_field_wrapper')?.querySelector('input.unique_value_field')
        if (!input)
        {
            return
        }

        navigator.clipboard.writeText(input.value)
            .then(() => Craft.cp.displayNotice('Copied to clipboard!'))
            .catch(() => Craft.cp.displayError('Failed to copy to clipboard'))
    })
})()
