import DecisionEditor from './DecisionEditor.vue'
import SetVariableEditor from './SetVariableEditor.vue'
import RetrieveEditor from './RetrieveEditor.vue'
import ChangeEditor from './ChangeEditor.vue'
import BlueprintCallableEditor from './BlueprintCallableEditor.vue'
import ClearVariableEditor from './ClearVariableEditor.vue'
import CreateObjectEditor from './CreateObjectEditor.vue'
import DeleteObjectEditor from './DeleteObjectEditor.vue'
import AssertConditionEditor from './AssertConditionEditor.vue'
import LogMessageEditor from './LogMessageEditor.vue'
import TerminateWorkflowEditor from './TerminateWorkflowEditor.vue'
import AggregateListEditor from './AggregateListEditor.vue'
import ListOperationEditor from './ListOperationEditor.vue'
import LoopEachEditor from './LoopEachEditor.vue'
import BreakLoopEditor from './BreakLoopEditor.vue'
import AgentActionEditor from './AgentActionEditor.vue'

/**
 * Map step_type to its editor component.
 * Unknown types will fall back to the JSON viewer in the shell.
 */
export const editorRegistry = {
    decision: DecisionEditor,
    condition: DecisionEditor,
    set_variable: SetVariableEditor,
    retrieve: RetrieveEditor,
    change: ChangeEditor,
    blueprint_callable: BlueprintCallableEditor,
    clear_variable: ClearVariableEditor,
    create_object: CreateObjectEditor,
    delete_object: DeleteObjectEditor,
    assert_condition: AssertConditionEditor,
    log_message: LogMessageEditor,
    terminate_workflow: TerminateWorkflowEditor,
    aggregate_list: AggregateListEditor,
    list_operation: ListOperationEditor,
    loop_each: LoopEachEditor,
    break_loop: BreakLoopEditor,
    ai_action: AgentActionEditor,
}

export const getEditorComponent = (stepType) => {
    if (!stepType) return null
    return editorRegistry[stepType] || editorRegistry[stepType?.toLowerCase?.()] || null
}

export default editorRegistry
